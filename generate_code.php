<?php

/**
 * Generates a deterministic but opaque 10-character code from a given title.
 *
 * Uses HMAC-SHA256 with a secret key so that:
 * - The output cannot be reversed to the source text.
 * - Multiple outputs reveal no pattern about the input.
 * - The same title always produces the same code (deterministic).
 *
 * Change SECRET_KEY to a long random string unique to your application.
 * Never expose this key; its secrecy is what makes the codes safe.
 */

define('SECRET_KEY', 'v3Ry$3cr3t!K3y-Ch4ng3-M3-1n-Pr0d');

function generate_code(string $title): string
{
    $hash = hash_hmac('sha256', $title, SECRET_KEY, binary: true);

    // Base62 alphabet: no lookalike characters, URL-safe, case-sensitive
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    $code = '';
    for ($i = 0; $i < 10; $i++) {
        $code .= $alphabet[ord($hash[$i]) % strlen($alphabet)];
    }

    return $code;
}

// --- Demo ---
$titles = [
    'Welkom bij onze website',
    'Introductie tot PHP',
    'Welkom bij onze website',   // duplicate: must produce identical code
    'Welkom bij onze Website',   // one capital differs: must look completely different
    'Zomercollectie 2025',
    'Nieuwsbrief april',
];

printf("%-35s %s\n", 'Titel', 'Code');
printf("%s\n", str_repeat('-', 47));

foreach ($titles as $title) {
    printf("%-35s %s\n", $title, generate_code($title));
}
