<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Http\Resources\GenerationHistoryResource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationHistoryController extends Controller
{
    /**
     * Display a paginated and filterable generation history for the authenticated customer.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $search = trim((string) $request->query('search', ''));
        $perPage = min(50, max(1, $request->integer('per_page', 10)));
        $status = (string) $request->query('status', 'all');

        $statusEnum = GenerationStatus::tryFrom($status);
        $isTerminalStatusFilter = in_array($statusEnum, [GenerationStatus::Succeeded, GenerationStatus::Failed], true);

        $generations = $user->generations()
            ->when($status === 'active', function ($query): void {
                $query->whereIn('status', [
                    GenerationStatus::Queued,
                    GenerationStatus::Processing,
                ]);
            })
            ->when($isTerminalStatusFilter, function ($query) use ($statusEnum): void {
                $query->where('status', $statusEnum);
            })
            ->when($search !== '', function ($query) use ($search): void {
                $query->where('pixelforge_id', '=', $search);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('Generations/History', [
            'generations' => GenerationHistoryResource::collection($generations),
            'has_active_generations' => $user->hasActiveGenerations(),
            'filters' => [
                'search' => $search !== '' ? $search : null,
                'status' => ($status === 'active' || $isTerminalStatusFilter) ? $status : 'all',
                'per_page' => $perPage,
            ],
            'statusOptions' => [
                ['label' => 'All', 'value' => 'all'],
                ['label' => 'Active', 'value' => 'active'],
                ['label' => 'Succeeded', 'value' => GenerationStatus::Succeeded],
                ['label' => 'Failed', 'value' => GenerationStatus::Failed],
            ],
        ]);
    }
}
