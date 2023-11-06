<?php
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => ['statamic.cp.authenticated'], 'namespace' => 'AltDesign\AltSitemap\Http\Controllers'], function() {
    // Settings
    Route::get('/alt-design/alt-sitemap/', 'AltSitemapController@index')->name('alt-sitemap.index');
    Route::post('/alt-design/alt-sitemap/', 'AltSitemapController@update')->name('alt-sitemap.update');
});
