<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    public function __construct(
        private NotificationService $notificationService
    ) {}

    public function index(): JsonResponse
    {
        $user = Auth::user();

        return response()->json([
            'notifications' => $this->notificationService->listForUser($user->company_id, $user->id),
            'unread_count' => $this->notificationService->unreadCount($user->company_id, $user->id),
        ]);
    }

    public function markRead(Notification $notification): JsonResponse
    {
        $notification = $this->notificationService->markAsRead($notification);

        return response()->json($notification);
    }

    public function markAllRead(): JsonResponse
    {
        $user = Auth::user();
        $count = $this->notificationService->markAllAsRead($user->company_id, $user->id);

        return response()->json(['marked' => $count]);
    }
}
