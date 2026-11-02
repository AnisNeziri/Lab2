<?php
namespace App\Services\Security;

final class PasswordPolicy
{
    public static function rules(): array
    {
        // Existing password hashes remain valid; newly chosen passwords avoid bcrypt truncation.
        return ['required','string','min:15','max:72',static function($attribute,$value,$fail){
            if(is_string($value) && strlen($value)>72) $fail('Choose a password of at least 15 characters and no more than 72 UTF-8 bytes.');
        }];
    }
}
