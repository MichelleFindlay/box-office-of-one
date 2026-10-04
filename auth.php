<?php

/**
 * One-time Trakt sign-in, only needed if your Trakt profile is private (a
 * public profile is readable with just client_id). Uses Trakt's device-code
 * flow: you're shown a short code to enter at trakt.tv/activate, and the
 * resulting token is stored in cache/trakt_token.php and refreshed
 * automatically from then on (see Trakt::refreshToken()).
 *
 * Needs client_id and client_secret set in config.php.
 *
 *   From a shell (preferred):
 *     php auth.php            sign in (waits while you approve the code)
 *     php auth.php status     show whether a token is stored
 *     php auth.php logout     forget the stored token
 *
 *   From a browser (for hosting without shell access):
 *     https://yourdomain.com/path/auth.php?token=YOUR_CRON_SECRET
 *   Only works when cron_secret is set in config.php — otherwise anyone who
 *   found this page could link their own Trakt account to your dashboard.
 */

require __DIR__ . '/lib/App.php';

$config = App::loadConfig();
$isCli = PHP_SAPI === 'cli';

function fail(string $message, int $httpStatus = 400): void
{
    global $isCli;
    if ($isCli) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
    http_response_code($httpStatus);
    echo '<!DOCTYPE html><meta charset="utf-8"><title>Trakt sign-in</title><p>' . htmlspecialchars($message) . '</p>';
    exit;
}

if (App::needsSetup($config)) {
    fail('Set client_id and username in config.php first (see config.sample.php).');
}
if ($config['client_secret'] === '') {
    fail('Set client_secret in config.php first — Trakt requires it to sign in.');
}

$app = App::boot($config);
$trakt = $app->trakt;
$deviceFile = __DIR__ . '/cache/trakt_device.php';

// --- CLI ---------------------------------------------------------------

if ($isCli) {
    $command = $argv[1] ?? 'login';

    if ($command === 'status') {
        echo $trakt->isAuthenticated() ? "Signed in — a Trakt token is stored.\n" : "Not signed in.\n";
        exit(0);
    }

    if ($command === 'logout') {
        $trakt->forgetToken();
        echo "Stored Trakt token removed.\n";
        exit(0);
    }

    $device = $trakt->requestDeviceCode();
    if ($device === null) {
        fail('Could not get a device code from Trakt — check client_id.');
    }

    echo "\n  Go to:       {$device['verification_url']}\n";
    echo "  Enter code:  {$device['user_code']}\n\n";
    echo "Waiting for approval (expires in " . (int) ceil($device['expires_in'] / 60) . " min)...\n";

    $interval = max(1, (int) $device['interval']);
    $deadline = time() + (int) $device['expires_in'];

    while (time() < $deadline) {
        sleep($interval);
        $status = $trakt->pollDeviceToken($device['device_code']);

        switch ($status) {
            case 'approved':
                echo "Signed in. Token stored in cache/trakt_token.php — it refreshes automatically.\n";
                exit(0);
            case 'pending':
                break;
            case 'slow_down':
                $interval++;
                break;
            case 'denied':
                fail('Sign-in was denied on trakt.tv.');
                break;
            case 'expired':
                fail('The code expired before it was approved — run this again.');
                break;
            default:
                fail('Trakt rejected the sign-in (' . $status . ') — check client_id and client_secret.');
        }
    }

    fail('The code expired before it was approved — run this again.');
}

// --- Browser -----------------------------------------------------------

$secret = $config['cron_secret'];
$provided = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
if ($secret === '' || !hash_equals($secret, $provided)) {
    fail('Browser sign-in needs cron_secret set in config.php, passed as ?token=... — or run "php auth.php" from a shell instead.', 403);
}

$action = $_POST['action'] ?? '';
$message = '';

if ($action === 'logout') {
    $trakt->forgetToken();
    @unlink($deviceFile);
    $message = 'Stored Trakt token removed.';
} elseif ($action === 'start') {
    $device = $trakt->requestDeviceCode();
    if ($device === null) {
        $message = 'Could not get a device code from Trakt — check client_id.';
    } else {
        $device['expires_at'] = time() + (int) $device['expires_in'];
        Trakt::writeGuarded($deviceFile, $device);
    }
}

$device = Trakt::readGuarded($deviceFile);
$refreshSeconds = 0;

if ($device !== null && !$trakt->isAuthenticated()) {
    if (time() >= (int) $device['expires_at']) {
        @unlink($deviceFile);
        $device = null;
        $message = 'The code expired before it was approved — start again.';
    } else {
        $status = $trakt->pollDeviceToken($device['device_code']);
        if ($status === 'approved') {
            @unlink($deviceFile);
            $device = null;
            $message = 'Signed in. The token refreshes automatically from now on.';
        } elseif ($status === 'pending' || $status === 'slow_down') {
            $refreshSeconds = max(5, (int) $device['interval'] + ($status === 'slow_down' ? 5 : 0));
        } else {
            @unlink($deviceFile);
            $device = null;
            $message = 'Trakt rejected the sign-in (' . $status . ') — start again.';
        }
    }
}

$signedIn = $trakt->isAuthenticated();
$selfUrl = 'auth.php?token=' . rawurlencode($provided);
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="referrer" content="no-referrer">
<?php if ($refreshSeconds): ?>
<meta http-equiv="refresh" content="<?= $refreshSeconds ?>;url=<?= $e($selfUrl) ?>">
<?php endif; ?>
<title>Trakt sign-in</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="wrap auth-wrap">
    <section class="panel">
        <h2>Trakt sign-in</h2>
        <?php if ($message): ?>
            <p><?= $e($message) ?></p>
        <?php endif; ?>

        <?php if ($signedIn): ?>
            <p>Signed in — a Trakt token is stored and refreshes automatically.</p>
            <form method="post" action="auth.php">
                <input type="hidden" name="token" value="<?= $e($provided) ?>">
                <button class="period-btn active" name="action" value="logout">Sign out</button>
                <a class="auth-link" href="index.php">Back to dashboard</a>
            </form>
        <?php elseif ($device): ?>
            <p>Go to <a class="auth-link" href="<?= $e($device['verification_url']) ?>" target="_blank" rel="noopener"><?= $e($device['verification_url']) ?></a> and enter:</p>
            <p class="auth-code"><?= $e($device['user_code']) ?></p>
            <p class="empty-state">This page checks for approval every <?= $refreshSeconds ?> seconds.</p>
        <?php else: ?>
            <p>Your profile is private, or you'd like the dashboard to read it as you. Sign in once and the token is kept up to date automatically.</p>
            <form method="post" action="auth.php">
                <input type="hidden" name="token" value="<?= $e($provided) ?>">
                <button class="period-btn active" name="action" value="start">Get a sign-in code</button>
            </form>
        <?php endif; ?>
    </section>
</div>
</body>
</html>
