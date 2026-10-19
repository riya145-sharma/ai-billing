<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Response;
use App\Core\Session;

/** Small helpers shared by admin controllers. */
trait AdminHelpers
{
    private static function intId(string $id): int
    {
        if (!ctype_digit($id) || (int)$id < 1) {
            throw new HttpException(404);
        }
        return (int)$id;
    }

    /** Flash an error, keep the submitted fields (never passwords) and go back to a page. */
    private static function failBack(string $message, string $path, array $keepFields = []): never
    {
        Session::flash('danger', $message);
        $old = [];
        foreach ($keepFields as $f) {
            $old[$f] = is_scalar($_POST[$f] ?? null) ? (string)$_POST[$f] : '';
        }
        Session::flashInput($old);
        Response::redirect($path);
    }

    private static function ok(string $message, string $path): never
    {
        Session::flash('success', $message);
        Response::redirect($path);
    }

    private static function post(string $key): string
    {
        return is_scalar($_POST[$key] ?? null) ? trim((string)$_POST[$key]) : '';
    }
}
