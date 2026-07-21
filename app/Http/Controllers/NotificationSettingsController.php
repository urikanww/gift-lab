<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\OrderMilestone;
use App\Models\PricingConfig;
use App\Services\AuditLogger;
use App\Services\OrderNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Which emails buyers receive, and how hard they are chased.
 *
 * Deliberately not folded into the generic pricing-config editor. That screen
 * edits raw key/value rows a superadmin already knows the meaning of; this one
 * is an operational setting staff need to reason about ("what will my client
 * actually get?"), and the switches do not exist as rows until someone changes
 * one — so a generic editor would show an empty list for a system that is
 * already sending mail.
 *
 * The milestone enum is the registry. Adding a case makes it appear here, which
 * is why this enumerates the enum rather than keeping its own list to drift out
 * of step with it.
 */
class NotificationSettingsController extends Controller
{
    private const SETTINGS_GROUP = 'notifications';

    private const CADENCE_GROUP = 'notifications_cadence';

    /** Human copy per milestone, so the screen is not a list of enum values. */
    private const LABELS = [
        'accepted' => ['Quote accepted', 'Confirms we received their acceptance.'],
        'artwork_approved' => ['Artwork approved', 'Tells them the price still needs agreeing.'],
        'proof_issued' => ['Revised proof issued', 'Sent for every proof after the first.'],
        'committed' => ['Order confirmed', 'Sent when the order is committed to production.'],
        'in_production' => ['In production', 'Sent when the order reaches the floor.'],
        'shipped' => ['Shipped', 'Sent when the order leaves you.'],
        'delivered' => ['Delivered', 'Sent when the last job closes.'],
        'cancelled' => ['Cancelled', 'Sent when staff cancel the order.'],
        'line_changed' => ['Item changed or dropped', 'Off by default — staff usually make this call personally.'],
        'reminder_price' => ['Chase: unanswered quote', 'Reminds a buyer who has not responded to a quote.'],
        'reminder_proof' => ['Chase: unapproved proof', 'Reminds a buyer sitting on a proof.'],
    ];

    public function __construct(
        private readonly OrderNotifier $notifier,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isStaff(), 403);

        $milestones = collect(OrderMilestone::cases())->map(function (OrderMilestone $milestone): array {
            [$label, $description] = self::LABELS[$milestone->value] ?? [$milestone->value, ''];

            return [
                'key' => $milestone->value,
                'label' => $label,
                'description' => $description,
                // The EFFECTIVE value, not the stored one. A milestone with no
                // row is still sending (or not) per its own default, and a
                // screen that showed it as "off" merely because nothing had been
                // written would be lying about what clients receive.
                'enabled' => $this->notifier->isEnabled($milestone),
                'default' => $milestone->enabledByDefault(),
            ];
        });

        return response()->json([
            'data' => $milestones,
            'cadence' => [
                'quote_days' => PricingConfig::value(self::CADENCE_GROUP, 'quote_days', [3, 7, 12]),
                'proof_days' => PricingConfig::value(self::CADENCE_GROUP, 'proof_days', [2, 5, 9]),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isStaff(), 403);

        $validated = $request->validate([
            'key' => ['required', 'string'],
            'enabled' => ['required', 'boolean'],
        ]);

        $milestone = OrderMilestone::tryFrom($validated['key']);
        abort_if($milestone === null, 404);

        $before = $this->notifier->isEnabled($milestone);

        $config = PricingConfig::updateOrCreate(
            ['group' => self::SETTINGS_GROUP, 'key' => $milestone->value],
            ['value' => $validated['enabled'], 'updated_by' => $request->user()->id],
        );

        // Audited like any other config change: turning a client-facing email
        // off is the sort of thing someone asks about three months later.
        $this->audit->log($config, 'notification_setting.updated', ['enabled' => $before], [
            'milestone' => $milestone->value,
            'enabled' => $validated['enabled'],
        ]);

        return response()->json([
            'key' => $milestone->value,
            'enabled' => $validated['enabled'],
        ]);
    }

    /** Reminder ladders: which days after the wait began a chaser goes out. */
    public function updateCadence(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isStaff(), 403);

        $validated = $request->validate([
            // Ascending, deduplicated, and capped: the ladder ends on purpose,
            // and an unbounded list would be a way to mail someone forever.
            'quote_days' => ['required', 'array', 'min:1', 'max:5'],
            'quote_days.*' => ['integer', 'min:1', 'max:90'],
            'proof_days' => ['required', 'array', 'min:1', 'max:5'],
            'proof_days.*' => ['integer', 'min:1', 'max:90'],
        ]);

        foreach (['quote_days', 'proof_days'] as $key) {
            $days = array_values(array_unique($validated[$key]));
            sort($days);

            $config = PricingConfig::updateOrCreate(
                ['group' => self::CADENCE_GROUP, 'key' => $key],
                ['value' => $days, 'updated_by' => $request->user()->id],
            );

            $this->audit->log($config, 'notification_cadence.updated', null, [$key => $days]);
        }

        return response()->json(['status' => 'ok']);
    }
}
