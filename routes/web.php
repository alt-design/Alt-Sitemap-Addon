<?php
use Illuminate\Support\Facades\Route;



Route::group(['middleware' => ['statamic.cp.authenticated'], 'namespace' => 'AltDesign\AltSitemap\Http\Controllers'], function() {
    Route::get('/sitemap.xml', 'AltSitemapController@index');
});
