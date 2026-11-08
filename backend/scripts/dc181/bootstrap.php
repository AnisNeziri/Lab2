<?php
require dirname(__DIR__).'/dc17/bootstrap.php';
$dc17Root='C:/Users/anisi/.codex/recovery/dc18-1-20261104';
config(['assistant.provider'=>'ollama','assistant.local_url'=>'http://127.0.0.1:11438','assistant.local_model'=>getenv('DC181_MODEL')?:'qwen3-vl:4b-instruct','assistant.local_vision_model'=>getenv('DC181_MODEL')?:'qwen3-vl:4b-instruct','assistant.model_tools'=>true,'assistant.model_timeout_seconds'=>45,'assistant.image_encoder'=>'siglip2','assistant.encoder_python'=>$dc17Root.'/encoder-venv/Scripts/python.exe','assistant.encoder_path'=>$dc17Root.'/siglip2','assistant.encoder_revision'=>'75de2d55ec2d0b4efc50b3e9ad70dba96a7b2fa2']);
// Only the explicitly configured loopback inference service is permitted in this isolated fixture.
Illuminate\Support\Facades\Http::allowStrayRequests(['http://127.0.0.1:11438/*']);
config(['assistant.model_revision'=>config('assistant.local_model')==='qwen3-vl:4b-instruct'?'84f7ed8042fa7cdbd902f7dcb7d0370e032095047cc3244179f263b77b5bc318':'']);
