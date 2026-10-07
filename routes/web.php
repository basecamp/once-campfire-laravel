<?php

use App\Http\Controllers\BoostsController;
use App\Http\Controllers\BotsController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LinksController;
use App\Http\Controllers\PeopleController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\RoomsController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\StorageController;
use App\Http\Controllers\TransfersController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/up', [HealthController::class, 'show']);
Route::get('/up.json', [HealthController::class, 'show']);

Route::get('/session/new', [SessionController::class, 'new']);
Route::post('/session', [SessionController::class, 'create']);

Route::get('/first_run', [PeopleController::class, 'firstRun']);
Route::post('/first_run', [PeopleController::class, 'provision']);
Route::match(['GET', 'POST'], '/join/{code}', [PeopleController::class, 'join']);
Route::get('/users/{user}/avatar', [StorageController::class, 'avatar']);
Route::get('/account/logo', [StorageController::class, 'logo']);
Route::get('/rails/active_storage/blobs/redirect/{signed}/{filename}', [StorageController::class, 'blob']);
Route::put('/rails/active_storage/disk/{signed}', [StorageController::class, 'disk']);
Route::get('/rails/active_storage/disk/{signed}/{filename}', [StorageController::class, 'diskDownload']);
Route::match(['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], '/rooms/{room}/{key}/messages/{id?}', [BotsController::class, 'api'])->whereNumber(['room', 'id']);
Route::get('/webmanifest.json', [LinksController::class, 'manifest']);
Route::get('/webmanifest', [LinksController::class, 'manifest']);
Route::get('/service-worker', [LinksController::class, 'worker']);
Route::get('/qr_code/{id}', [TransfersController::class, 'qr']);
Route::get('/session/transfers/{id}', [TransfersController::class, 'show']);
Route::match(['PATCH', 'PUT'], '/session/transfers/{id}', [TransfersController::class, 'update']);
Route::get('/rails/active_storage/representations/redirect/{signed}/{variation}/{filename}', [StorageController::class, 'representation']);
Route::match(['POST', 'DELETE'], '/rooms/{room}/{key}/messages/{id}/boosts/{boost?}', [BotsController::class, 'boost'])->whereNumber(['room', 'id', 'boost']);
Route::middleware('campfire.auth')->group(function () {
    Route::delete('/users/{user}/avatar', [StorageController::class, 'deleteAvatar']);
    Route::delete('/account/logo', [StorageController::class, 'deleteLogo']);
    Route::post('/unfurl_link', [LinksController::class, 'unfurl']);
    Route::get('/', [ChatController::class, 'root']);
    Route::get('/rooms', [ChatController::class, 'root']);
    Route::delete('/session', [SessionController::class, 'destroy']);
    Route::get('/rooms/{id}/@{message}', [ChatController::class, 'room'])->whereNumber(['id', 'message']);
    Route::get('/rooms/{id}', [ChatController::class, 'room'])->whereNumber('id');
    Route::get('/rooms/{room}/messages', [ChatController::class, 'messages'])->whereNumber('room');
    Route::post('/rooms/{room}/messages', [ChatController::class, 'create'])->whereNumber('room');
    Route::get('/rooms/{room}/messages/{id}', [ChatController::class, 'show'])->whereNumber(['room', 'id']);
    Route::get('/rooms/{room}/messages/{id}/edit', [ChatController::class, 'edit'])->whereNumber(['room', 'id']);
    Route::match(['PATCH', 'PUT'], '/rooms/{room}/messages/{id}', [ChatController::class, 'update'])->whereNumber(['room', 'id']);
    Route::delete('/rooms/{room}/messages/{id}', [ChatController::class, 'destroy'])->whereNumber(['room', 'id']);
    Route::get('/rooms/{room}/refresh', [ChatController::class, 'refresh'])->whereNumber('room');
    Route::get('/users/{user}/sidebar', [ChatController::class, 'sidebar']);
    Route::get('/searches', [ChatController::class, 'search']);
    Route::post('/searches', [ChatController::class, 'recordSearch']);
    Route::delete('/searches/clear', [ChatController::class, 'clearSearch']);

    Route::get('/rooms/{kind}/new', [RoomsController::class, 'new'])->whereIn('kind', ['opens', 'closeds', 'directs']);
    Route::post('/rooms/{kind}', [RoomsController::class, 'create'])->whereIn('kind', ['opens', 'closeds', 'directs']);
    Route::get('/rooms/{kind}/{id}/edit', [RoomsController::class, 'edit'])->whereIn('kind', ['opens', 'closeds', 'directs'])->whereNumber('id');
    Route::match(['PATCH', 'PUT'], '/rooms/{kind}/{id}', [RoomsController::class, 'update'])->whereIn('kind', ['opens', 'closeds', 'directs'])->whereNumber('id');
    Route::delete('/rooms/{id}', [RoomsController::class, 'destroy'])->whereNumber('id');
    Route::get('/rooms/{room}/settings', [RoomsController::class, 'settings'])->whereNumber('room');
    Route::match(['GET', 'PATCH', 'PUT'], '/rooms/{room}/involvement', [RoomsController::class, 'involvement'])->whereNumber('room');
    Route::get('/users/{user}/profile', [PeopleController::class, 'profile']);
    Route::match(['PATCH', 'PUT'], '/users/{user}/profile', [PeopleController::class, 'profile']);
    Route::get('/users/{id}', [PeopleController::class, 'show'])->whereNumber('id');
    Route::get('/autocompletable/users', [PeopleController::class, 'autocomplete']);
    Route::get('/account/edit', [PeopleController::class, 'account']);
    Route::match(['PATCH', 'PUT'], '/account', [PeopleController::class, 'account']);
    Route::get('/account/custom_styles/edit', [PeopleController::class, 'customStyles']);
    Route::patch('/account/custom_styles', [PeopleController::class, 'customStyles']);
    Route::post('/account/join_code', [PeopleController::class, 'resetJoinCode']);
    Route::match(['PATCH', 'PUT', 'DELETE'], '/account/users/{id}', [PeopleController::class, 'member'])->whereNumber('id');
    Route::match(['POST', 'DELETE'], '/users/{id}/ban', [PeopleController::class, 'ban'])->whereNumber('id');
    Route::get('/messages/{id}/boosts', [BoostsController::class, 'index'])->whereNumber('id');
    Route::get('/messages/{id}/boosts/new', [BoostsController::class, 'new'])->whereNumber('id');
    Route::post('/messages/{id}/boosts', [BoostsController::class, 'create'])->whereNumber('id');
    Route::delete('/messages/{id}/boosts/{boost}', [BoostsController::class, 'destroy'])->whereNumber(['id', 'boost']);
    Route::get('/account/bots', [BotsController::class, 'index']);
    Route::get('/account/bots/new', [BotsController::class, 'form']);
    Route::post('/account/bots', [BotsController::class, 'create']);
    Route::get('/account/bots/{id}/edit', [BotsController::class, 'form'])->whereNumber('id');
    Route::match(['PATCH', 'PUT'], '/account/bots/{id}', [BotsController::class, 'update'])->whereNumber('id');
    Route::delete('/account/bots/{id}', [BotsController::class, 'destroy'])->whereNumber('id');
    Route::put('/account/bots/{id}/key', [BotsController::class, 'resetKey'])->whereNumber('id');
    Route::get('/users/{user}/push_subscriptions', [PushController::class, 'index']);
    Route::post('/users/{user}/push_subscriptions', [PushController::class, 'create']);
    Route::delete('/users/{user}/push_subscriptions/{id}', [PushController::class, 'destroy'])->whereNumber('id');
    Route::post('/users/{user}/push_subscriptions/{id}/test_notifications', [PushController::class, 'test'])->whereNumber('id');
    Route::post('/rails/active_storage/direct_uploads', [StorageController::class, 'directUpload']);
    Route::get('/rooms/{kind}/{id}', [RoomsController::class, 'show'])->whereIn('kind', ['opens', 'closeds', 'directs'])->whereNumber('id');
    Route::delete('/rooms/{kind}/{id}', [RoomsController::class, 'deleteNamespaced'])->whereIn('kind', ['opens', 'closeds', 'directs'])->whereNumber('id');
    Route::match(['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], '/messages/{id?}', function (Request $r, ?int $id = null) {
        $room = (int) $r->input('room_id');
        abort_unless($room, 404);
        $c = app(ChatController::class);

        return match ($r->method()) {
            'GET' => $id ? $c->show($r, $room, $id) : $c->messages($r, $room),'POST' => $c->create($r, $room),'PATCH','PUT' => $c->update($r, $room, $id),'DELETE' => $c->destroy($r, $room, $id)
        };
    })->whereNumber('id');
});
