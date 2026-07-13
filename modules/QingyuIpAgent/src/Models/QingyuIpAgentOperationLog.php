<?php

namespace Modules\QingyuIpAgent\Models;

use Illuminate\Database\Eloquent\Model;

class QingyuIpAgentOperationLog extends Model
{
    protected $table = 'qingyu_ip_agent_operation_logs';

    protected $guarded = [];

    public $timestamps = false;
}
