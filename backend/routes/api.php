<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PhotoController;
use App\Http\Controllers\Api\PlaceController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\VisitController;
use App\Http\Controllers\Api\VisitDishController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::put('profile', [AuthController::class, 'updateProfile']);
        Route::put('password', [AuthController::class, 'changePassword']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    // Places & Wishlist (F-01, F-05, F-06, F-07)
    Route::get('places', [PlaceController::class, 'index']);
    Route::post('places', [PlaceController::class, 'store']);
    Route::get('places/{place}', [PlaceController::class, 'show']);
    Route::put('places/{place}', [PlaceController::class, 'update']);
    Route::delete('places/{place}', [PlaceController::class, 'destroy']);
    Route::post('places/{place}/complete-wishlist', [PlaceController::class, 'completeWishlist']);

    // Visits (F-02, F-08)
    Route::post('places/{place}/visits', [VisitController::class, 'store']);
    Route::get('visits/{visit}', [VisitController::class, 'show']);
    Route::put('visits/{visit}', [VisitController::class, 'update']);
    Route::delete('visits/{visit}', [VisitController::class, 'destroy']);

    // Visit Dishes (F-03)
    Route::post('visits/{visit}/dishes', [VisitDishController::class, 'store']);
    Route::put('dishes/{dish}', [VisitDishController::class, 'update']);
    Route::delete('dishes/{dish}', [VisitDishController::class, 'destroy']);

    // Photos (F-04)
    Route::get('photos', [PhotoController::class, 'index']);
    Route::post('visits/{visit}/photos', [PhotoController::class, 'store']);
    Route::delete('photos/{photo}', [PhotoController::class, 'destroy']);

    // Tags (F-08)
    Route::get('tags', [TagController::class, 'index']);
    Route::post('tags', [TagController::class, 'store']);
    Route::delete('tags/{tag}', [TagController::class, 'destroy']);

    // Complementary Post-MVP Features (Stats, Random Wishlist, CSV Export)
    Route::get('stats', [StatsController::class, 'summary']);
    Route::get('wishlist/random', [StatsController::class, 'randomWishlist']);
    Route::get('export/csv', [StatsController::class, 'exportCsv']);
});
