{{--
    Staff-facing "a queued job exhausted its retries" dead-letter alert.
    Structure, palette and spacing come from mail/layouts/shell so it can
    never drift from the buyer emails. No CTA - there is no admin failed-jobs
    screen yet, so this is informational only.
--}}
@include('mail.layouts.shell', [
    'heading' => 'A background job failed',
    'preheader' => $jobName.' failed after its retries were exhausted.',
    'footer' => 'Gift Lab · Internal notification.',

    'body' => 'The job <strong>'.e($jobName).'</strong> failed and exhausted its retries. '
        .'It has been moved to the dead-letter table for review.',

    'rows' => '
        <tr>
            <td style="padding:14px 0; font-family:Helvetica,Arial,sans-serif; font-size:13px; color:#8a8a99;">Job</td>
            <td align="right" style="padding:14px 0; font-family:Helvetica,Arial,sans-serif; font-size:14px; color:#14141a; font-weight:600;">'.e($jobName).'</td>
        </tr>
        <tr>
            <td style="padding:14px 0; border-top:1px solid #f0f0f6; font-family:Helvetica,Arial,sans-serif; font-size:13px; color:#8a8a99;">UUID</td>
            <td align="right" style="padding:14px 0; border-top:1px solid #f0f0f6; font-family:Helvetica,Arial,sans-serif; font-size:14px; color:#14141a;">'.e($uuid ?? '—').'</td>
        </tr>
        <tr>
            <td style="padding:14px 0; border-top:1px solid #f0f0f6; font-family:Helvetica,Arial,sans-serif; font-size:13px; color:#8a8a99;">Connection / queue</td>
            <td align="right" style="padding:14px 0; border-top:1px solid #f0f0f6; font-family:Helvetica,Arial,sans-serif; font-size:14px; color:#14141a;">'.e($connectionName).' / '.e($queue).'</td>
        </tr>
        <tr>
            <td style="padding:14px 0; border-top:1px solid #f0f0f6; font-family:Helvetica,Arial,sans-serif; font-size:13px; color:#8a8a99; vertical-align:top;">Exception</td>
            <td align="right" style="padding:14px 0; border-top:1px solid #f0f0f6; font-family:Helvetica,Arial,sans-serif; font-size:14px; color:#14141a; line-height:21px;">'.nl2br(e($exceptionMessage)).'</td>
        </tr>',
])
