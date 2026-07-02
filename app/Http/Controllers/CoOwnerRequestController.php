<?php

namespace App\Http\Controllers;

use App\Bots\Channels\Telegram\TelegramSession;
use App\Bots\Channels\Telegram\TelegramTransport;
use App\Bots\Dto\OutboundReply;
use App\Http\Resources\CoOwnerRequestResource;
use App\Models\CoOwnerRequest;
use App\Models\TelegramAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * TODO: should i review this file by my self .
 * Space-owner facing endpoints for the "add another space owner" workflow.
 *
 * A person asks (via the Telegram bot) to co-manage one of the owner's
 * garages; the request lands here as a pending {@see CoOwnerRequest}. The
 * owner lists and resolves those requests from the dashboard. Approval links
 * the requester's Telegram chat to the owner's account so both phones operate
 * the same garages, and notifies the requester in the bot.
 *
 * Every action is scoped to the authenticated owner. Route middleware
 * (`role:SPACE_OWNER,SUPER_ADMIN`) gates access; the `owner_id` filter here is
 * a second, data-level guard so one owner can never touch another's requests.
 */
class CoOwnerRequestController extends Controller
{
    public function __construct(
        private readonly TelegramTransport $telegram,
    ) {}

    /**
     * Pending co-owner requests addressed to the signed-in owner's garages.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $requests = CoOwnerRequest::query()
            ->where('owner_id', $request->user()->id)
            ->where('status', CoOwnerRequest::STATUS_PENDING)
            ->with('park:id,name')
            ->latest()
            ->paginate(20);

        return CoOwnerRequestResource::collection($requests);
    }

    /**
     * Approve a request: link the requester's Telegram chat to this owner's
     * account, then tell them (in the bot) that they now control the garage.
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $owner   = $request->user();
        $coOwner = $this->pendingForOwner($owner->id, $id);

        DB::transaction(function () use ($coOwner, $owner) {
            // One chat maps to exactly one account — repoint it to the owner
            // (covers a phone that previously had its own bot identity).
            TelegramAccount::updateOrCreate(
                ['chat_id' => $coOwner->telegram_chat_id],
                ['user_id' => $owner->id, 'is_primary' => false],
            );

            // Point the requester's live session at the owner and drop any
            // in-progress flow so their next "ابدأ" opens the owner's menu.
            $session = TelegramSession::firstOrNew(['chat_id' => $coOwner->telegram_chat_id]);
            $session->user_id    = $owner->id;
            $session->flow       = null;
            $session->step       = 'idle';
            $session->data       = [];
            $session->expires_at = null;
            $session->save();

            $coOwner->update([
                'status'     => CoOwnerRequest::STATUS_APPROVED,
                'decided_by' => $owner->id,
                'decided_at' => now(),
            ]);
        });

        $this->notifyApproved($coOwner);

        return response()->json([
            'message' => 'تمت الموافقة على الطلب وربط الجهاز بالحساب.',
            'data'    => new CoOwnerRequestResource($coOwner->fresh('park')),
        ]);
    }

    /**
     * Reject a request and let the requester know politely.
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        $owner   = $request->user();
        $coOwner = $this->pendingForOwner($owner->id, $id);

        $coOwner->update([
            'status'     => CoOwnerRequest::STATUS_REJECTED,
            'decided_by' => $owner->id,
            'decided_at' => now(),
        ]);

        $this->telegram->sendTo(
            $coOwner->telegram_chat_id,
            OutboundReply::text(
                "ℹ️ لم تتم الموافقة على طلبك للانضمام إلى الكراج في الوقت الحالي.\n"
                . "يمكنك التواصل مع صاحب الكراج أو إرسال *ابدأ* لتقديم طلب جديد."
            ),
        );

        return response()->json([
            'message' => 'تم رفض الطلب.',
            'data'    => new CoOwnerRequestResource($coOwner->fresh('park')),
        ]);
    }

    /**
     * Fetch a still-pending request that belongs to this owner, or 404/409.
     */
    private function pendingForOwner(string $ownerId, string $id): CoOwnerRequest
    {
        $coOwner = CoOwnerRequest::where('owner_id', $ownerId)
            ->with('park')
            ->find($id);

        abort_if($coOwner === null, HttpResponse::HTTP_NOT_FOUND);

        abort_if(
            $coOwner->status !== CoOwnerRequest::STATUS_PENDING,
            HttpResponse::HTTP_CONFLICT,
            'تمت معالجة هذا الطلب مسبقاً.',
        );

        return $coOwner;
    }

    /**
     * Notify the requester that their request was approved, naming the garage.
     */
    private function notifyApproved(CoOwnerRequest $coOwner): void
    {
        $parkName = $coOwner->park?->name ?? 'الكراج';

        $this->telegram->sendTo(
            $coOwner->telegram_chat_id,
            OutboundReply::text(
                "🎉 تمت الموافقة على طلبك!\n"
                . "يمكنك الآن التحكم بكراج *{$parkName}* بشكل كامل.\n\n"
                . "أرسل *ابدأ* للبدء."
            ),
        );
    }
}
