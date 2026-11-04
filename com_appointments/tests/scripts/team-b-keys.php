<?php
/**
 * Work package B: encryption and keys. Scaffold assertions: no key material
 * exists after installation, and the key tables store no plaintext secret.
 */

return static function (TeamSuite $suite): void {
    $suite->test('No recovery key and no user key exist after installation', static function () use ($suite) {
        return $suite->count("SELECT COUNT(*) FROM {$suite->prefix}appointments_keyring") === 0
            && $suite->count("SELECT COUNT(*) FROM {$suite->prefix}appointments_user_keys") === 0;
    });

    $suite->test('User keys store only a public key and a wrapped secret', static function () use ($suite) {
        return $suite->columns('appointments_user_keys') === ['user_id', 'key_version', 'public_key', 'password_wrapped', 'password_salt', 'pwhash_opslimit', 'pwhash_memlimit', 'row_mac', 'created_utc', 'modified_utc'];
    });

    $suite->test('libsodium is available on the test server', static function () {
        return function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt') && function_exists('sodium_crypto_box_seal');
    });
};
