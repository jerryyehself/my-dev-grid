<?php

namespace App\Http\Controllers;

use App\Models\Technique;
use App\Service\GitService;
use App\Service\SaveReposDataService;
use Illuminate\Http\Request;

class TechniqueController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $service = new SaveReposDataService;
        return $service->save_repos_data();
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Technique $technique)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Technique $technique)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Technique $technique)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Technique $technique)
    {
        //
    }
}
