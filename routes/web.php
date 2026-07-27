<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CompetitionController;
use App\Http\Controllers\Admin\CompetitionDayController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\IgcValidationController;
use App\Http\Controllers\Admin\OgnAssignController;
use App\Http\Controllers\Admin\ParticipantController;
use App\Http\Controllers\Admin\PilotController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\TurnpointController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home');
});

Route::get('/podium', function () {
    return view('podium');
});

Route::get('/podium-general', function () {
    return view('podium-general');
});

Route::get('/podium-display', function () {
    return view('podium-display');
});

// Admin auth
Route::get('/admin/login', [AuthController::class, 'showLogin'])->name('admin.login.form');
Route::post('/admin/login', [AuthController::class, 'login'])->name('admin.login');

// Admin panel (protected)
Route::prefix('admin')->middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('admin.logout');
    Route::get('/', [DashboardController::class, 'index'])->name('admin.dashboard');

    Route::resource('pilots', PilotController::class)->names('admin.pilots')->except('show');
    Route::resource('turnpoints', TurnpointController::class)->names('admin.turnpoints')->except('show');
    Route::post('turnpoints/import-cup', [TurnpointController::class, 'importCup'])->name('admin.turnpoints.import-cup');
    Route::resource('participants', ParticipantController::class)->names('admin.participants')->except('show');

    Route::get('/competition', [CompetitionController::class, 'edit'])->name('admin.competition.edit');
    Route::put('/competition', [CompetitionController::class, 'update'])->name('admin.competition.update');
    Route::post('/competition/activate', [CompetitionController::class, 'activate'])->name('admin.competition.activate');
    Route::post('/competition/close', [CompetitionController::class, 'closeCompetition'])->name('admin.competition.close');

    Route::post('days', [CompetitionDayController::class, 'store'])->name('admin.days.store');
    Route::post('days/{day}/start', [CompetitionDayController::class, 'start'])->name('admin.days.start');
    Route::post('days/{day}/close', [CompetitionDayController::class, 'close'])->name('admin.days.close');
    Route::get('days/{day}/scores', [CompetitionDayController::class, 'editScores'])->name('admin.days.scores');
    Route::post('days/{day}/scores', [CompetitionDayController::class, 'updateScores'])->name('admin.days.updateScores');

    // IGC validation per pilot
    Route::get('days/{day}/pilots/{pilot}/igc', [IgcValidationController::class, 'show'])->name('admin.igc.show');
    Route::post('days/{day}/pilots/{pilot}/igc', [IgcValidationController::class, 'process'])->name('admin.igc.process');
    Route::post('days/{day}/pilots/{pilot}/igc/save', [IgcValidationController::class, 'save'])->name('admin.igc.save');

    Route::resource('settings', SettingController::class)->names('admin.settings')->only(['index', 'store', 'update', 'destroy']);

    Route::get('ogn-assign', [OgnAssignController::class, 'index'])->name('admin.ogn-assign.index');
    Route::post('ogn-assign', [OgnAssignController::class, 'store'])->name('admin.ogn-assign.store');
});

// Déploiement automatique — authentifié par signature HMAC, pas par session
Route::post('/webhook/github', \App\Http\Controllers\GithubWebhookController::class)
    ->middleware('throttle:30,1')
    ->name('webhook.github');

Route::get('/api/ogn/positions', [\App\Http\Controllers\OgnController::class, 'positions']);
Route::get('/positions', [\App\Http\Controllers\OgnController::class, 'positions']);
Route::get('/api/competition', [\App\Http\Controllers\ApiController::class, 'competition']);
Route::get('/api/participants', [\App\Http\Controllers\ApiController::class, 'participants']);
Route::get('/api/live', [\App\Http\Controllers\ApiController::class, 'live']);
Route::get('/api/pilots', [\App\Http\Controllers\ApiController::class, 'pilots']);
Route::get('/api/live-pilots', [\App\Http\Controllers\ApiController::class, 'livePilots']);
Route::get('/api/general-ranking', [\App\Http\Controllers\ApiController::class, 'generalRanking']);
Route::get('/api/turnpoints', [\App\Http\Controllers\ApiController::class, 'turnpoints']);
Route::get('/api/settings', [\App\Http\Controllers\ApiController::class, 'settings']);
Route::post('/api/validate-turnpoint', [\App\Http\Controllers\ApiController::class, 'validateTurnpoint']);
Route::get('/api/validated-turnpoints', [\App\Http\Controllers\ApiController::class, 'validatedTurnpoints']);
Route::post('/api/dev/positions', function (\Illuminate\Http\Request $request) {
    if (!env('DEV_FAKE_POSITIONS', false)) {
        abort(404);
    }
    $request->validate([
        'participant_id' => 'required|integer',
        'lat' => 'required|numeric',
        'lng' => 'required|numeric',
    ]);
    $ok = \App\Http\Controllers\OgnController::updateFakePosition(
        (int) $request->input('participant_id'),
        (float) $request->input('lat'),
        (float) $request->input('lng')
    );
    return response()->json(['ok' => $ok], $ok ? 200 : 404);
});

Route::get('/tiles/osm/{z}/{x}/{y}.png', [\App\Http\Controllers\TileProxyController::class, 'osm'])->where(['z' => '\\d+', 'x' => '\\d+', 'y' => '\\d+']);
Route::get('/tiles/openaip/{z}/{x}/{y}.png', [\App\Http\Controllers\TileProxyController::class, 'openaip'])->where(['z' => '\\d+', 'x' => '\\d+', 'y' => '\\d+']);
