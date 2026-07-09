<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBatchRequest;
use App\Http\Resources\BatchCollection;
use App\Http\Resources\BatchResource;
use App\Models\Batch;
use App\Contracts\CreateBatchServiceInterface;

class BatchController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): BatchCollection
    {
        $batches = Batch::with('batchFiles')->latest()->get();
        return new BatchCollection($batches);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreBatchRequest $request, CreateBatchServiceInterface $service): \Illuminate\Http\JsonResponse
    {
        $batch = $service->execute($request->validated('urls'));
        
        $batch->load('batchFiles');

        return (new BatchResource($batch))
            ->response()
            ->setStatusCode(202);
    }

    /**
     * Display the specified resource.
     */
    public function show(Batch $batch): BatchResource
    {
        $batch->loadMissing('batchFiles');
        return new BatchResource($batch);
    }
}
