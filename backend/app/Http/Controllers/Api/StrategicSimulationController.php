<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StrategicSimulationService;
use Illuminate\Http\Request;

class StrategicSimulationController extends Controller
{
    public function __construct(private StrategicSimulationService $s) {}

    public function options()
    {
        return response()->json($this->s->options());
    }

    public function index()
    {
        return response()->json($this->s->summary());
    }

    public function show(int $id)
    {
        return response()->json($this->s->get($id));
    }

    public function state(int $id)
    {
        return response()->json($this->s->state($id));
    }

    public function store(Request $r)
    {
        return response()->json($this->s->submit($r->all()), 202);
    }

    public function rerun(Request $r, int $id)
    {
        return response()->json($this->s->rerun($id, $r->all()), 202);
    }

    public function derive(Request $r, int $id)
    {
        return response()->json($this->s->derive($id, $r->all()), 202);
    }

    public function cancel(int $id)
    {
        return response()->json($this->s->cancel($id));
    }

    public function compare(Request $r)
    {
        $v = $r->validate(['ids' => 'required|array|min:2|max:4', 'ids.*' => 'integer|distinct']);

        return response()->json($this->s->compare($v['ids']));
    }

    public function sensitivity(Request $r, int $id)
    {
        return response()->json($this->s->sensitivity($id, $r->all()));
    }

    public function response(Request $r, int $id)
    {
        return response()->json($this->s->prepareResponse($id, $r->all()), 202);
    }
}
