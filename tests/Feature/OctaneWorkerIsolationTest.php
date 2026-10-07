<?php

namespace Tests\Feature;

use App\Support\RailsCrypto;
use Illuminate\Container\Container;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Laravel\Octane\Testing\Fakes\FakeWorker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives different users, one after another, through ONE application booted the way the
 * FrankenPHP worker boots it (Octane's ApplicationFactory + Worker, with config/octane.php's
 * listeners and warmed services), and proves nothing of one request reaches the next.
 */
final class OctaneWorkerIsolationTest extends TestCase
{
    private string $dir;

    private array $savedEnv = [];

    private FakeWorker $worker;

    private FakeClient $client;

    private array $users = [];

    private int $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/campfire-octane-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/files', 0777, true);
        $database = $this->dir.'/production.sqlite3';

        $pdo = new \PDO('sqlite:'.$database);
        $pdo->exec(file_get_contents(dirname(__DIR__, 2).'/database/schema.sql'));
        $now = gmdate('Y-m-d H:i:s.000000');
        $pdo->exec("INSERT INTO accounts (name, join_code, settings, created_at, updated_at) VALUES ('Campfire', 'abcd-efgh-ijkl', '{}', '$now', '$now')");
        $insertUser = $pdo->prepare("INSERT INTO users (name, email_address, password_digest, role, status, created_at, updated_at) VALUES (?, ?, ?, 0, 0, '$now', '$now')");
        foreach (['alice' => 'Alice Anderson', 'bob' => 'Bob Brown'] as $key => $name) {
            $insertUser->execute([$name, $key.'@example.org', password_hash('secret123456', PASSWORD_BCRYPT, ['cost' => 4])]);
            $this->users[$key] = ['id' => (int) $pdo->lastInsertId(), 'name' => $name, 'email' => $key.'@example.org'];
        }
        $pdo->exec("INSERT INTO rooms (name, type, creator_id, created_at, updated_at) VALUES ('Watercooler', 'Rooms::Open', {$this->users['alice']['id']}, '$now', '$now')");
        $this->room = (int) $pdo->lastInsertId();
        foreach ($this->users as $user) {
            $pdo->exec("INSERT INTO memberships (room_id, user_id, involvement, created_at, updated_at) VALUES ({$this->room}, {$user['id']}, 'mentions', '$now', '$now')");
        }
        $pdo = null;

        // As under FrankenPHP: not a console app (so CSRF is enforced), a real SQLite file shared
        // by every request, and nothing written into the checkout.
        $this->setEnv([
            'APP_RUNNING_IN_CONSOLE' => 'false',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'DB_DATABASE' => $database,
            'STORAGE_PATH' => $this->dir.'/files',
        ]);

        $this->client = new FakeClient([]);
        $this->worker = new FakeWorker(new ApplicationFactory(dirname(__DIR__, 2)), $this->client);
        $this->worker->boot();
        $this->worker->application()->make('config')->set('campfire.events', $this->dir.'/events.log');
    }

    protected function tearDown(): void
    {
        $this->worker->terminate();
        HandleExceptions::flushState($this);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        foreach ($this->savedEnv as $key => [$server, $env]) {
            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
            if ($env === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
        }
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_users_csrf_tokens_and_shared_view_data_do_not_leak_between_requests(): void
    {
        $alice = $this->users['alice'];
        $bob = $this->users['bob'];

        // 1. Anonymous: login page. Its CSRF token lives in the Rails session cookie.
        $login = $this->request('GET', '/session/new');
        $this->assertSame(200, $login->getStatusCode());
        $this->assertNoCurrentUser($login);
        $anonymousToken = $this->csrfToken($login);

        // 2. Alice signs in with that token.
        $signedIn = $this->request('POST', '/session', ['email_address' => $alice['email'], 'password' => 'secret123456', 'authenticity_token' => $anonymousToken], $this->cookies($login));
        $this->assertSame(302, $signedIn->getStatusCode(), (string) $signedIn->getContent());
        $aliceCookies = $this->cookies($signedIn);
        $this->assertArrayHasKey('session_token', $aliceCookies);

        // 3. Alice's room page.
        $aliceRoom = $this->request('GET', '/rooms/'.$this->room, [], $aliceCookies);
        $this->assertSame(200, $aliceRoom->getStatusCode());
        $this->assertCurrentUser($aliceRoom, $alice);
        $aliceToken = $this->csrfToken($aliceRoom);
        $aliceCookies = $this->cookies($aliceRoom) + $aliceCookies;

        // 4. A signed-out visitor right after Alice, on the same worker: no trace of her.
        $anonymous = $this->request('GET', '/session/new');
        $this->assertSame(200, $anonymous->getStatusCode());
        $this->assertNoCurrentUser($anonymous);
        $this->assertStringNotContainsString($alice['name'], (string) $anonymous->getContent());
        $this->assertNotContains($this->csrfToken($anonymous), [$anonymousToken, $aliceToken]);
        $anonymousRedirect = $this->request('GET', '/rooms/'.$this->room);
        $this->assertSame(302, $anonymousRedirect->getStatusCode());
        $this->assertStringEndsWith('/session/new', $anonymousRedirect->headers->get('Location'));

        // 5. Bob, signed in through his own session cookie.
        $bobCookies = ['session_token' => $this->sessionCookie($bob['id'])];
        $bobRoom = $this->request('GET', '/rooms/'.$this->room, [], $bobCookies);
        $this->assertSame(200, $bobRoom->getStatusCode());
        $this->assertCurrentUser($bobRoom, $bob);
        $bobToken = $this->csrfToken($bobRoom);
        $this->assertNotSame($aliceToken, $bobToken);
        $bobCookies = $this->cookies($bobRoom) + $bobCookies;
        $sidebar = $this->request('GET', '/users/me/sidebar', [], $bobCookies);
        $this->assertSame(200, $sidebar->getStatusCode());
        $personal = $this->worker->application()->make(RailsCrypto::class)->streamName(rtrim(base64_encode('gid://campfire/User/'.$bob['id']), '=').':rooms');
        $this->assertStringContainsString($personal, (string) $sidebar->getContent());

        // 6. Alice's CSRF token is worthless with Bob's session; Bob's own works and posts as Bob.
        $forged = $this->request('POST', '/rooms/'.$this->room.'/messages', ['message' => ['body' => '<div>forged</div>'], 'authenticity_token' => $aliceToken], $bobCookies);
        $this->assertContains($forged->getStatusCode(), [419, 422]);
        $posted = $this->request('POST', '/rooms/'.$this->room.'/messages', ['message' => ['body' => '<div>Bob says hi</div>', 'client_message_id' => 'bob-1'], 'authenticity_token' => $bobToken], $bobCookies);
        $this->assertSame(200, $posted->getStatusCode(), (string) $posted->getContent());
        $this->assertStringContainsString('turbo-stream', (string) $posted->getContent());
        $this->assertStringContainsString('Bob says hi', (string) $posted->getContent());
        $creator = (new \PDO('sqlite:'.$this->dir.'/production.sqlite3'))->query("SELECT creator_id FROM messages WHERE client_message_id = 'bob-1'")->fetchColumn();
        $this->assertSame($bob['id'], (int) $creator);

        // 7. Back to Alice: still Alice, and her session cookie still carries her own token.
        $again = $this->request('GET', '/rooms/'.$this->room, [], $aliceCookies);
        $this->assertCurrentUser($again, $alice);
        $this->assertSame($aliceToken, $this->csrfToken($again));
        $this->assertStringContainsString('Bob says hi', (string) $again->getContent());
    }

    public function test_per_request_services_do_not_accumulate_in_the_worker(): void
    {
        // A transaction left open on the worker's connection is rolled back once a request is over.
        $connection = $this->worker->application()->make('db')->connection();
        $connection->beginTransaction();
        $connection->table('users')->where('id', $this->users['bob']['id'])->update(['name' => 'Leaked']);
        $this->request('GET', '/up');
        $this->assertSame(0, $connection->transactionLevel());
        $name = (new \PDO('sqlite:'.$this->dir.'/production.sqlite3'))->query('SELECT name FROM users WHERE id = '.$this->users['bob']['id'])->fetchColumn();
        $this->assertSame('Bob Brown', $name);
        $this->assertWriterLockFree();

        // Nor does one SQLite already ended itself leave the writer lock held: the transaction
        // count cleanup finds nothing on the PDO to roll back.
        (new \PDO('sqlite:'.$this->dir.'/production.sqlite3'))->exec('CREATE TABLE fixture_names (name TEXT UNIQUE ON CONFLICT ROLLBACK); INSERT INTO fixture_names VALUES (\'taken\')');
        $connection->beginTransaction();
        try {
            $connection->table('fixture_names')->insert(['name' => 'taken']);
            $this->fail('The duplicate should have thrown.');
        } catch (QueryException) {
        }
        $this->request('GET', '/up');
        $this->assertWriterLockFree();

        $cookies = ['session_token' => $this->sessionCookie($this->users['alice']['id'])];
        $events = $this->worker->application()->make('events');
        $avatar = '/users/'.$this->worker->application()->make(RailsCrypto::class)->signedId($this->users['bob']['id'], 'User', 'avatar').'/avatar';
        $this->request('GET', $avatar, [], $cookies);
        $listeners = count($events->getListeners(TransactionRolledBack::class));
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->request('GET', $avatar, [], $cookies)->getStatusCode());
        }
        // BlobStorage, resolved by every avatar request, used to add two listeners per instance.
        $this->assertSame($listeners, count($events->getListeners(TransactionRolledBack::class)));
        $this->assertSame([], array_keys(array_diff_key($this->worker->application()->make('view')->getShared(), ['__env' => 1, 'app' => 1])));
    }

    private function request(string $method, string $uri, array $parameters = [], array $cookies = []): Response
    {
        $request = Request::create('http://campfire.test'.$uri, $method, $parameters, $cookies, [], ['REMOTE_ADDR' => '127.0.0.1']);
        $this->client->requests = [$request];
        $before = count($this->client->responses);
        $this->worker->run();
        $this->assertSame([], $this->client->errors);
        $this->assertCount($before + 1, $this->client->responses);

        return end($this->client->responses);
    }

    /** The response's cookies, as a browser would send them back. */
    private function cookies(Response $response): array
    {
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $cookies;
    }

    private function csrfToken(Response $response): string
    {
        $this->assertSame(1, preg_match('~<meta name="csrf-token" content="([^"]+)"~', (string) $response->getContent(), $m));

        return html_entity_decode($m[1]);
    }

    private function assertCurrentUser(Response $response, array $user): void
    {
        $html = (string) $response->getContent();
        $this->assertStringContainsString('<meta name="current-user-id" content="'.$user['id'].'">', $html);
        $this->assertStringContainsString('<meta name="current-user-name" content="'.e($user['name']).'">', $html);
        $this->assertSame(1, substr_count($html, 'name="current-user-id"'));
    }

    private function assertWriterLockFree(): void
    {
        $handle = fopen($this->dir.'/production.sqlite3.lock', 'c');
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB), 'The worker still holds the writer lock.');
        fclose($handle);
    }

    private function assertNoCurrentUser(Response $response): void
    {
        $this->assertStringNotContainsString('current-user-id', (string) $response->getContent());
        $this->assertStringNotContainsString('current-user-name', (string) $response->getContent());
    }

    private function sessionCookie(int $userId): string
    {
        $token = bin2hex(random_bytes(18));
        $now = gmdate('Y-m-d H:i:s.000000');
        (new \PDO('sqlite:'.$this->dir.'/production.sqlite3'))->prepare("INSERT INTO sessions (user_id, token, last_active_at, created_at, updated_at) VALUES (?, ?, '$now', '$now', '$now')")->execute([$userId, $token]);

        return $this->worker->application()->make(RailsCrypto::class)->signCookie('session_token', $token);
    }

    private function setEnv(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->savedEnv[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null];
            $_SERVER[$key] = $_ENV[$key] = $value;
        }
    }
}
