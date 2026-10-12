<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JiraTicketSync extends Model
{
    protected $fillable = ['ticket_id', 'jira_issue_key', 'last_synced_status'];
}
