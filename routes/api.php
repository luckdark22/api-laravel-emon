<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AcademicYearController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\TeacherController;
use App\Http\Controllers\Api\DudiController;
use App\Http\Controllers\Api\PlacementController;
use App\Http\Controllers\Api\PlacementMutationController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\JournalController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\IssueController;
use App\Http\Controllers\Api\VisitController;
use App\Http\Controllers\Api\AssessmentController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\HealthController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public routes
Route::post('/auth/login', [AuthController::class, 'login']);

// Health check (no auth)
Route::get('/health/db', [HealthController::class, 'db']);

// Protected routes
Route::middleware('jwt.auth')->group(function () {
    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
    Route::post('/fcm-token', [App\Http\Controllers\Api\UserTokenController::class, 'update']);
    Route::post('/notifications/send', [App\Http\Controllers\Api\NotificationController::class, 'send']);

    // Academic Years
    Route::get('/academic-years', [AcademicYearController::class, 'index']);
    Route::post('/academic-years', [AcademicYearController::class, 'store']);
    Route::get('/academic-years/active', [AcademicYearController::class, 'active']);
    Route::get('/academic-years/{id}', [AcademicYearController::class, 'show']);
    Route::put('/academic-years/{id}', [AcademicYearController::class, 'update']);
    Route::delete('/academic-years/{id}', [AcademicYearController::class, 'destroy']);
    Route::post('/academic-years/{id}/activate', [AcademicYearController::class, 'activate']);

    // Students
    Route::get('/students', [StudentController::class, 'index']);
    Route::post('/students', [StudentController::class, 'store']);
    Route::post('/students/import', [StudentController::class, 'import']);
    Route::get('/students/{id}', [StudentController::class, 'show']);
    Route::match(['put', 'patch'], '/students/{id}', [StudentController::class, 'update']);
    Route::delete('/students/{id}', [StudentController::class, 'destroy']);
    Route::post('/students/{id}/reset-password', [StudentController::class, 'resetPassword']);

    // Teachers
    Route::get('/teachers', [TeacherController::class, 'index']);
    Route::post('/teachers/import', [TeacherController::class, 'import']); // Must be before /{id}
    Route::post('/teachers', [TeacherController::class, 'store']);
    Route::get('/teachers/{id}', [TeacherController::class, 'show']);
    Route::match(['put', 'patch'], '/teachers/{id}', [TeacherController::class, 'update']);
    Route::delete('/teachers/{id}', [TeacherController::class, 'destroy']);
    Route::post('/teachers/{id}/reset-password', [TeacherController::class, 'resetPassword']);

    // DUDI
    Route::get('/dudi', [DudiController::class, 'index']);
    Route::post('/dudi', [DudiController::class, 'store']);
    Route::get('/dudi/{id}', [DudiController::class, 'show']);
    Route::match(['put', 'patch'], '/dudi/{id}', [DudiController::class, 'update']);
    Route::delete('/dudi/{id}', [DudiController::class, 'destroy']);
    Route::post('/dudi/{id}/reset-password', [DudiController::class, 'resetPassword']);

    // Placements - mutations routes MUST come before {id} routes
    Route::get('/placements/mutations', [PlacementMutationController::class, 'index']);
    Route::post('/placements/mutations', [PlacementMutationController::class, 'store']);
    Route::post('/placements/mutations/teacher', [PlacementMutationController::class, 'teacherStore']);
    Route::get('/placements/mutations/teacher', [PlacementMutationController::class, 'teacherIndex']);
    Route::match(['put', 'patch'], '/placements/mutations/{id}/status', [PlacementMutationController::class, 'updateStatus']);

    // Placements CRUD
    Route::get('/placements', [PlacementController::class, 'index']);
    Route::post('/placements', [PlacementController::class, 'store']);
    Route::get('/placements/{id}', [PlacementController::class, 'show']);
    Route::match(['put', 'patch'], '/placements/{id}', [PlacementController::class, 'update']);
    Route::delete('/placements/{id}', [PlacementController::class, 'destroy']);

    // Attendance
    Route::get('/attendance', [AttendanceController::class, 'index']);
    Route::get('/attendance/status', [AttendanceController::class, 'status']);
    Route::get('/attendance/today', [AttendanceController::class, 'today']);
    Route::get('/attendance/filters', [AttendanceController::class, 'filters']);
    Route::get('/attendance/missing', [AttendanceController::class, 'missing']);
    Route::post('/attendance/clock-in', [AttendanceController::class, 'clockIn']);
    Route::post('/attendance/clock-out', [AttendanceController::class, 'clockOut']);

    // Journals
    Route::get('/journals', [JournalController::class, 'index']);
    Route::post('/journals', [JournalController::class, 'store']);
    Route::get('/journals/{id}', [JournalController::class, 'show']);
    Route::match(['put', 'patch'], '/journals/{id}', [JournalController::class, 'update']);
    Route::delete('/journals/{id}', [JournalController::class, 'destroy']);
    Route::match(['put', 'patch'], '/journals/{id}/status', [JournalController::class, 'updateStatus']);

    // Leaves
    Route::get('/leaves', [LeaveController::class, 'index']);
    Route::post('/leaves', [LeaveController::class, 'store']);
    Route::get('/leaves/{id}', [LeaveController::class, 'show']);
    Route::match(['put', 'patch'], '/leaves/{id}', [LeaveController::class, 'update']);
    Route::delete('/leaves/{id}', [LeaveController::class, 'destroy']);
    Route::match(['put', 'patch'], '/leaves/{id}/status', [LeaveController::class, 'updateStatus']);

    // Issues
    Route::get('/issues', [IssueController::class, 'index']);
    Route::post('/issues', [IssueController::class, 'store']);
    Route::get('/issues/admin', [IssueController::class, 'adminIndex']);
    Route::get('/issues/teacher', [IssueController::class, 'teacherIndex']);
    Route::match(['put', 'patch'], '/issues/{id}/resolve', [IssueController::class, 'resolve']);

    // Visits
    Route::get('/visits', [VisitController::class, 'index']);
    Route::get('/visits/locations', [VisitController::class, 'locations']);
    Route::post('/visits', [VisitController::class, 'store']);
    Route::get('/visits/{id}', [VisitController::class, 'show']);
    Route::put('/visits/{id}', [VisitController::class, 'update']);
    Route::delete('/visits/{id}', [VisitController::class, 'destroy']);

    // Assessment
    Route::get('/assessment', [AssessmentController::class, 'index']);
    Route::post('/assessment', [AssessmentController::class, 'store']);
    Route::get('/assessment/mentor', [AssessmentController::class, 'mentorIndex']);
    Route::get('/assessment/student', [AssessmentController::class, 'studentIndex']);
    Route::get('/assessment/student/{id}', [AssessmentController::class, 'studentShow']);
    Route::get('/assessment/my-students', [AssessmentController::class, 'myStudents']);

    // Reports
    Route::get('/reports', [ReportController::class, 'index']);
    Route::get('/reports/me', [ReportController::class, 'me']);
    Route::post('/reports/upload', [ReportController::class, 'upload']);
    Route::post('/reports', [ReportController::class, 'store']);
    Route::get('/reports/{id}', [ReportController::class, 'show']);
    Route::match(['put', 'patch'], '/reports/{id}', [ReportController::class, 'update']);
    Route::delete('/reports/{id}', [ReportController::class, 'destroy']);
    Route::match(['put', 'patch'], '/reports/{id}/review', [ReportController::class, 'review']);
    Route::get('/reports/student/{id}', [ReportController::class, 'studentReports']);

    // Dashboard
    Route::get('/dashboard/admin', [DashboardController::class, 'admin']);
    Route::get('/dashboard/admin/analysis', [DashboardController::class, 'adminAnalysis']);
    Route::get('/dashboard/admin/dudi-stats', [DashboardController::class, 'adminDudiStats']);
    Route::get('/dashboard/admin/dudi-stats/{id}/details', [DashboardController::class, 'dudiDetails']);
    Route::get('/dashboard/teacher', [DashboardController::class, 'teacher']);
    Route::get('/dashboard/teacher/analysis', [DashboardController::class, 'teacherAnalysis']);
    Route::get('/dashboard/teacher/dudi-stats', [DashboardController::class, 'teacherDudiStats']);
    Route::get('/dashboard/teacher/dudi-stats/{dudiId}/details', [DashboardController::class, 'teacherDudiDetails']);
    Route::get('/dashboard/student', [DashboardController::class, 'student']);
    Route::get('/dashboard/mentor', [DashboardController::class, 'mentor']);

    // Profile
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::match(['put', 'patch'], '/profile', [ProfileController::class, 'update']);
    Route::match(['post', 'put'], '/profile/change-password', [AuthController::class, 'changePassword']);

    // Settings
    Route::get('/settings', [SettingController::class, 'index']);
    Route::match(['put', 'patch'], '/settings', [SettingController::class, 'update']);
    Route::post('/settings/upload-logo', [SettingController::class, 'uploadLogo']);

    // Holidays
    Route::get('/holidays', [HolidayController::class, 'index']);
    Route::post('/holidays', [HolidayController::class, 'store']);
    Route::get('/holidays/{id}', [HolidayController::class, 'show']);
    Route::put('/holidays/{id}', [HolidayController::class, 'update']);
    Route::delete('/holidays/{id}', [HolidayController::class, 'destroy']);

    // Uploads
    Route::post('/uploads', [UploadController::class, 'store']);

    // Chat
    Route::get('/chat', [ChatController::class, 'index']);
    Route::post('/chat', [ChatController::class, 'store']);

});

// Storage Proxy (CORS fix for artisan serve) - Public Access
Route::get('/storage/{path}', function ($path) {
    $fullPath = storage_path('app/public/' . $path);
    if (!file_exists($fullPath))
        abort(404);
    return response()->file($fullPath, [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, OPTIONS',
    ]);
})->where('path', '.*');
