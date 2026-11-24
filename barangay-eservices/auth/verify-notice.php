<?php
require __DIR__ . '/../app/bootstrap.php';

// Signed-in but unverified users land here; every other protected page sends them back here.
$user = require_login(false);
if (is_verified($user)) {
    redirect(home_path_for_current_user());
}

if (is_post()) {
    csrf_verify();
    if (isset($_POST['code'])) {
        $result = confirm_email_code((int) $user['user_id'], (string) $_POST['code']);
        $messages = [
            'ok'      => ['success', 'Your Gmail address is verified. Your account is now active.'],
            'format'  => ['warning', 'Enter the 6-digit code from the email.'],
            'missing' => ['warning', 'There is no active code yet. Use “Resend verification email”.'],
            'expired' => ['warning', 'That code has expired. Request a new one below.'],
            'locked'  => ['danger', 'Too many incorrect codes. Request a new email below.'],
            'invalid' => ['danger', 'That code does not match the latest email. Check the code and try again.'],
        ];
        [$type, $text] = $messages[$result] ?? $messages['invalid'];
        flash($type, $text);
        redirect($result === 'ok' ? home_path_for_current_user() : 'auth/verify-notice.php');
    }

    $cooldown = (int) config('resend_cooldown_seconds', 60);
    $last = db_value('SELECT MAX(created_at) FROM email_verifications WHERE user_id = ?', [(int) $user['user_id']]);
    $wait = $last ? $cooldown - (time() - strtotime($last)) : 0;
    if ($wait > 0) {
        flash('warning', "Please wait $wait more second(s) before requesting another email.");
    } elseif (send_verification_email($user)) {
        flash('success', 'A new code was sent to ' . $user['email'] . '. Earlier codes and links no longer work.');
    } else {
        flash('danger', 'The email could not be sent right now. Please try again in a few minutes.');
    }
    redirect('auth/verify-notice.php');
}

$pageTitle = 'Verify your Gmail';
$activeNav = 'verify';
require APP_ROOT . '/app/views/header.php';
?>
<div class="form-narrow mx-auto">
  <div class="page-head"><div><h1>Verify your Gmail</h1><p>Your account is created but not yet active.</p></div></div>
  <div class="panel" data-verify-poll="<?= e(url('auth/verify-status.php')) ?>">
    <dl class="details mb-3">
      <dt>Gmail</dt><dd><?= e($user['email']) ?></dd>
      <dt>Status</dt><dd><span class="status status-ready" id="verify-live-badge">Waiting for Gmail</span></dd>
    </dl>
    <p id="verify-live-status" class="verify-live"><span class="verify-dot" aria-hidden="true"></span><span>This page checks Gmail confirmation every few seconds. Enter the 6-digit code, or open the link in the email.</span></p>
    <form method="post" class="mb-3">
      <?= csrf_field() ?>
      <label class="form-label" for="code">Verification code</label>
      <input class="form-control verify-code" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required placeholder="000000">
      <div class="form-text mb-3">The code is in the latest email and expires in <?= (int) config('verification_ttl_minutes', 60) ?> minutes.</div>
      <button type="submit" class="btn btn-primary">Verify now</button>
    </form>
    <form method="post">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-outline-primary">Resend verification email</button>
    </form>
  </div>
</div>
<?php require APP_ROOT . '/app/views/footer.php'; ?>
