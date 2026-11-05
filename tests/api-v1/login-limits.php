<?php

/*
| The attempt limit on the two logins - required by run.php after every other
| suite, sharing its scope. It has to stay last: it fills the buckets of the one
| address the whole run calls from, and any login after it would get a 429.
*/

// A bucket lasts until its window ends. Don't fill one across that line, or the
// count starts again part way through.
$secondsLeft = 300 - time() % 300;
if ($secondsLeft < 30) {
    sleep($secondsLeft);
}

// Earlier suites logged in too. Start from nothing so the limit is exact.
$pdo->exec("DELETE FROM api_rate_limits WHERE bucket_key LIKE 'login:%' OR bucket_key LIKE 'admin-login:%'");

seed($pdo, "users", [
    ["id" => 90, "name" => "Lena Limit", "email" => "lena@limit.test", "approved" => 1, "session_token" => "tok-lena",
     "password" => password_hash("lena-password", PASSWORD_DEFAULT)],
]);
seed($pdo, "admins", [
    ["id" => 90, "name" => "Abe Limit", "email" => "abe@limit.test", "role" => "owner", "session_token" => "tok-abe",
     "password" => password_hash("abe-password", PASSWORD_DEFAULT)],
]);

$limitedLogins = [
    "client" => ["api/login.php", "users", "lena@limit.test", "lena-password", "Login successful"],
    "staff" => ["api/admin-login.php", "admins", "abe@limit.test", "abe-password", "Admin login successful"],
];

// Runs one after the other from one address, so the second proving its full
// allowance is still there is what shows the two don't share a bucket.
foreach ($limitedLogins as $who => [$path, $table, $email, $password, $welcome]) {
    // Under the limit the replies are what they always were. That's 2 attempts.
    [$status, $r] = post($path, "", ["email" => $email, "password" => $password]);
    check("$who login: right password under the limit", $status === 200 && ($r["success"] ?? null) === true
        && ($r["message"] ?? "") === $welcome && !empty($r["token"]), "HTTP $status " . json_encode($r));
    [$status, $r] = post($path, "", ["email" => $email]);
    check("$who login: missing password under the limit", $status === 200 && ($r["success"] ?? null) === false
        && ($r["message"] ?? "") === "Email and password are required", "HTTP $status " . json_encode($r));

    // 18 wrong passwords use up the rest of the 20.
    $allowed = 0;
    for ($i = 0; $i < 18; $i++) {
        [$status, $r] = post($path, "", ["email" => $email, "password" => "wrong-$i"]);
        if ($status === 200 && ($r["message"] ?? "") === "Invalid email or password") {
            $allowed++;
        }
    }
    check("$who login: 20 attempts are allowed", $allowed === 18, "$allowed of 18 wrong passwords got the usual reply");

    [$status, $r] = post($path, "", ["email" => $email, "password" => "wrong-again"]);
    check("$who login: attempt 21 is a 429", $status === 429 && ($r["success"] ?? null) === false && !empty($r["message"]),
        "HTTP $status " . json_encode($r));

    // Past the limit even the right password opens nothing.
    $stmt = $pdo->prepare("SELECT session_token FROM `$table` WHERE email = ?");
    $stmt->execute([$email]);
    $before = $stmt->fetchColumn();
    [$status, $r] = post($path, "", ["email" => $email, "password" => $password]);
    $stmt->execute([$email]);
    check("$who login: the right password past the limit gets no session",
        $status === 429 && !isset($r["token"]) && $stmt->fetchColumn() === $before, "HTTP $status " . json_encode($r));

    // The test server is on loopback, a trusted proxy, so it believes this.
    [$status, $r] = post($path, "", ["email" => $email, "password" => $password], ["X-Forwarded-For" => "203.0.113.90"]);
    check("$who login: another address has its own limit", $status === 200 && ($r["success"] ?? null) === true,
        "HTTP $status " . json_encode($r));
}
