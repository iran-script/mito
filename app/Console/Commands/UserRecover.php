<?php
namespace App\Console\Commands;
use App\Domain\Telegram\InteractionStateResolver;
use App\Domain\Users\User;
use Illuminate\Console\Command;
class UserRecover extends Command {
 protected $signature='mito:user-recover {user}'; protected $description='Recover only transient Telegram state for a user';
 public function handle(InteractionStateResolver $resolver): int {
  $v=(string)$this->argument('user'); $u=ctype_digit($v)?User::find((int)$v):User::where('public_mito_id',ltrim($v,'/'))->first();
  if(!$u){$this->error('User not found.');return self::FAILURE;} $s=$resolver->recover($u); $this->info('Recovered user '.$u->id.' to '.$s->mode.'. Durable data was preserved.'); return self::SUCCESS;
 }
}