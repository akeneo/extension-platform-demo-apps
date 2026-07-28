<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;

class HmacSignatureValidator
{
    /**
     * Validates the request signature against one or more secrets.
     * Returns true if the signature matches any of the provided secrets.
     *
     * @param string|string[] $secrets
     */
    public function validate(
        Request $request,
        string|array $secrets,
        string $headerName = 'signature',
        string $algorithm = 'sha512',
    ): bool {
        $header = $request->headers->get($headerName, '');

        if ($header === '') {
            return false;
        }

        // Action UI extensions prefix the hash with "sha512="; Event Platform sends raw hex
        $prefix = $algorithm . '=';
        $received = str_starts_with($header, $prefix)
            ? substr($header, strlen($prefix))
            : $header;

        $body = $request->getContent();

        foreach ((array) $secrets as $secret) {
            if (hash_equals(hash_hmac($algorithm, $body, $secret), $received)) {
                return true;
            }
        }

        return false;
    }
}
