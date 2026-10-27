<?php

// Pesan validasi berbahasa Indonesia untuk aturan yang dipakai IGI. Aturan yang tidak tercantum memakai bahasa Inggris (cadangan).
return [
    'accepted' => ':Attribute harus disetujui.',
    'array' => ':Attribute harus berupa daftar.',
    'boolean' => ':Attribute harus berisi benar atau salah.',
    'confirmed' => 'Konfirmasi :attribute tidak sama.',
    'date' => ':Attribute bukan tanggal yang sah.',
    'different' => ':Attribute harus berbeda dari :other.',
    'email' => ':Attribute harus berupa alamat email yang sah.',
    'exists' => ':Attribute yang dipilih tidak ditemukan.',
    'in' => ':Attribute yang dipilih tidak sah.',
    'integer' => ':Attribute harus berupa bilangan bulat.',
    'numeric' => ':Attribute harus berupa angka.',
    'regex' => 'Format :attribute tidak sah.',
    'required' => ':Attribute wajib diisi.',
    'string' => ':Attribute harus berupa teks.',
    'unique' => ':Attribute sudah dipakai.',
    'uuid' => ':Attribute harus berupa UUID yang sah.',

    'between' => [
        'numeric' => ':Attribute harus antara :min dan :max.',
        'string' => ':Attribute harus antara :min dan :max karakter.',
        'array' => ':Attribute harus berisi :min sampai :max butir.',
    ],
    'min' => [
        'numeric' => ':Attribute minimal :min.',
        'string' => ':Attribute minimal :min karakter.',
        'array' => ':Attribute minimal berisi :min butir.',
    ],
    'max' => [
        'numeric' => ':Attribute maksimal :max.',
        'string' => ':Attribute maksimal :max karakter.',
        'array' => ':Attribute maksimal berisi :max butir.',
    ],

    'custom' => [],

    'attributes' => [
        'name' => 'nama',
        'email' => 'email',
        'password' => 'kata sandi',
        'current_password' => 'kata sandi saat ini',
        'phone_number' => 'nomor telepon',
        'requested_role' => 'peran yang diminta',
        'organization' => 'instansi atau posko',
        'request_note' => 'keterangan',
        'title' => 'judul',
        'body' => 'isi',
        'level' => 'tingkat',
        'reason' => 'alasan',
        'role' => 'peran',
        'camp_id' => 'posko',
        'warehouse_id' => 'gudang',
        'expires_in_hours' => 'masa berlaku',
        'hours' => 'rentang waktu',
        'min_magnitude' => 'kekuatan minimum',
        'source' => 'sumber',
    ],
];
