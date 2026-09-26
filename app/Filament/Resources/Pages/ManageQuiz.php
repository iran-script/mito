<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\QuizResource;
use Filament\Resources\Pages\ManageRecords;

class ManageQuiz extends ManageRecords
{
    protected static string $resource = QuizResource::class;
}
