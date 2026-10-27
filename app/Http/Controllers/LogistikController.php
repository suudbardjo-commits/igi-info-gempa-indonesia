<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman konsol logistik. Datanya dimuat halaman lewat API (hak akses ditegakkan di sana),
 * jadi controller ini hanya menyajikan kerangka halaman.
 */
class LogistikController extends Controller
{
    public function permintaan(): Response
    {
        return Inertia::render('Logistik/Permintaan');
    }

    public function pengiriman(): Response
    {
        return Inertia::render('Logistik/Pengiriman');
    }

    public function stok(): Response
    {
        return Inertia::render('Logistik/Stok');
    }

    public function laporan(): Response
    {
        return Inertia::render('Logistik/Laporan');
    }

    public function pengumuman(): Response
    {
        abort_unless(auth()->user()->can('announcement.manage'), 403);

        return Inertia::render('Logistik/Pengumuman');
    }

    public function petugas(): Response
    {
        abort_unless(auth()->user()->can('user.review'), 403);

        return Inertia::render('Logistik/Petugas');
    }

    public function audit(): Response
    {
        abort_unless(auth()->user()->can('audit.view'), 403);

        return Inertia::render('Logistik/Audit');
    }
}
