<?php

declare(strict_types=1);

/*
| argon2id, not bcrypt: bcrypt reads only the first 72 bytes, and the old app
| accepted passwords of up to 200 characters (all of them counted by its
| scrypt). argon2id is memory-hard like the scrypt it replaces. Other keys
| come from the framework's own config/hashing.php.
*/
return [

    'driver' => env('HASH_DRIVER', 'argon2id'),

    // Rehashing happens in AuthService, inside the sign-in write, never in Auth::attempt.
    'rehash_on_login' => false,

];
