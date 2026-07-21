<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/


Route::get('/', function () {
    return ['Laravel' => app()->version()];
});



Route::get('/check-proc-open', function () {
    return function_exists('proc_open') ? 'proc_open is enabled' : 'proc_open is disabled';
});


// require __DIR__.'/auth.php';
