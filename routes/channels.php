<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('pro-light-628-staging.private-chat.{receiverId}', function ($user, $receiverId) {
    return (int) $user->id === (int) $receiverId;
});

/*
|--------------------------------------------------------------------------
| Catalogue Progress Channel
|--------------------------------------------------------------------------
| Only the owner of the établissement can listen to its catalogue progress.
| The etabId in the channel name must match an établissement owned by the
| authenticated user.
*/
Broadcast::channel('catalogue.{etabId}', function ($user, $etabId) {
    if (!$user || (int) $etabId <= 0) {
        return false;
    }

    return DB::table('etablissements')
        ->where('id', (int) $etabId)
        ->where('user_id', (int) $user->id)
        ->exists();
});