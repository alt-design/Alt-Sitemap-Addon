<?php
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => ['statamic.cp.authenticated'], 'namespace' => 'AltDesign\AltSitemap\Http\Controllers'], function() {
    // Settings
    Route::get('/alt-design/alt-sitemap/', 'AltRedirectController@index')->name('alt-redirect.index');
});
