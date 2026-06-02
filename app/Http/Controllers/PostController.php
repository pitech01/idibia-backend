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

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string',
            'content' => 'required|string',
            'category' => 'required|string',
            'image' => 'nullable|image|max:2048', // 2MB Max
        ]);

        $path = null;
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('posts', 'public');
        }

        $post = \App\Models\Post::create([
            'title' => $request->title,
            'slug' => \Illuminate\Support\Str::slug($request->title),
            'content' => $request->content,
            'description' => $request->description,
            'category' => $request->category,
            'type' => $request->type ?? 'article',
            'image_url' => $path ? url('storage/' . $path) : null,
            'time_to_read' => $request->time_to_read ?? '5 min read',
            'is_featured' => $request->is_featured ?? false,
            'author_name' => $request->author_name ?? 'Dr. Idibia',
        ]);

        return response()->json($post, 201);
    }

    public function update(Request $request, $id)
    {
        $post = \App\Models\Post::findOrFail($id);
        
        $request->validate([
            'title' => 'sometimes|string',
            // image is optional on update
            'image' => 'nullable|image|max:2048', 
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('posts', 'public');
            $post->image_url = url('storage/' . $path);
        }

        $post->fill($request->only([
            'title', 'content', 'description', 'category', 'type', 
            'time_to_read', 'is_featured', 'author_name'
        ]));

        if ($request->has('title')) {
            $post->slug = \Illuminate\Support\Str::slug($request->title);
        }

        $post->save();

        return response()->json($post);
    }

    public function destroy($id)
    {
        $post = \App\Models\Post::findOrFail($id);
        $post->delete();
        return response()->json(['message' => 'Post deleted successfully']);
    }
}
