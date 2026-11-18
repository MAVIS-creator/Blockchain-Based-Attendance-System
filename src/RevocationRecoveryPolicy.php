<?php
declare(strict_types=1);

/**
 * A genuine administrator revocation is NEVER eligible for automatic removal.
 * IP address is deliberately absent: campus NAT makes it non-unique.
 * Temporary locks must have system-written provenance and a short expiry.
 */
function bbas_auto_recovery_eligible(
    string $ticketFingerprint,
    string $blockedFingerprint,
    string $blockedToken,
    array $revocation,
    int $now
): bool {
    if ($ticketFingerprint === '' || $blockedFingerprint === '' || !hash_equals($blockedFingerprint, $ticketFingerprint)) {
        return false;
    }
    $pos = strrpos($ticketFingerprint, '_');
    if ($pos === false) return false;
    $token = substr($ticketFingerprint, $pos + 1);
    if ($token === '' || !hash_equals($token, $blockedToken)) {
        return false;
    }
    if (($revocation['source'] ?? '') !== 'temporary_tab_lock' ||
        ($revocation['issued_by'] ?? '') !== 'bbas_policy_engine' ||
        trim((string)($revocation['by'] ?? '')) !== '') {
        return false;
    }
    $issued = (int)($revocation['at'] ?? 0);
    $expires = (int)($revocation['expiry'] ?? 0);
    return $issued > 0 && $issued <= $now && $expires >= $now &&
        $expires > $issued && ($expires - $issued) <= 1800;
}
