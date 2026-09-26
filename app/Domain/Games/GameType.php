<?php

namespace App\Domain\Games;

enum GameType: string
{
    case RockPaperScissors = 'rock_paper_scissors';
    case TruthOrDare = 'truth_or_dare';
    case SpeedQuiz = 'speed_quiz';
    case ThisOrThat = 'this_or_that';
    case TwoTruthsOneLie = 'two_truths_one_lie';
    case GuessInterest = 'guess_interest';
    case GuessNumber = 'guess_number';
}
