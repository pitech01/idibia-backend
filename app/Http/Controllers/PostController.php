<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PostController extends Controller
{
    public function index()
    {
        return \App\Models\Post::orderBy('created_at', 'desc')->get();
    }

    public function show($id)
    {
        return \App\Models\Post::findOrFail($id);
    }
}
