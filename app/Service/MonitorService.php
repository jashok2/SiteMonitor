<?php
/**
 * Created by PhpStorm.
 * User: shellus
 * Date: 2018/1/3
 * Time: 16:46
 */

namespace App\Service;


use App\Jobs\MonitorJob;
use App\Mail\MonitorNotice;
use App\Monitor;
use App\Snapshot;
use App\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class MonitorService
{
	/**
	 * 删除一个监控任务，及其关联数据
	 *
	 * @param $monitorId
	 *
	 * @return bool
	 */
	static public function deleteMonitor($monitorId){
		/** @var Monitor $monitor */
		$monitor = Monitor::findOrFail($monitorId);

		\DB::beginTransaction();
		$monitor->snapshots()->delete();
		$monitor->data()->delete();
		$monitor->delete();
		\DB::commit();
		return true;
	}

	static public function createMonitor(array $attributes = []){
		\DB::beginTransaction();
		/** @var Monitor $monitor */
		$monitor = Monitor::create($attributes);
		$monitor->data()->create();
		\DB::commit();
		self::joinQueue($monitor);
		return $monitor;
	}
	static public function quickGenerateMonitor($projectId, $url, $matchType, $matchContent, $matchReverse)
	{
		$attributes=[
			'project_id' => $projectId,
			'title' => Monitor::generateTitle(),
			'request_url' => $url,
			'request_method' => "GET",
			'request_headers' => "",
			'request_body' => "",
			'is_enable' => true,
			'request_follow_location' => true,
			'request_nobody' => true,
			'interval_normal' => 60 * 5,
			'interval_match' => 60 * 5,
			'interval_error' => 30,
			'match_reverse' => $matchReverse,
			'match_type' => $matchType,
			'match_content' => $matchContent,
		];

		return self::createMonitor($attributes);
	}

    static public function runAllMonitor()
    {
        $jobs = Monitor::all();
        foreach ($jobs as $job) {
            self::joinQueue($job);
        }

        return true;
    }

    /**
     * 将一个监控任务加入列队，会自动设置延迟
     * 注意：此方法会被MonitorJob递归调用
     *
     * @param Monitor $monitor
     *
     * @return void
     */
    static public function joinQueue(Monitor $monitor)
    {
        $interval = $monitor->interval_normal;
        try {
            $lastSnapshot = $monitor->lastSnapshot();
            if ($lastSnapshot->is_error) {
                $interval = $monitor->interval_error;
            } elseif ($lastSnapshot->is_match) {
                $interval = $monitor->interval_match;
            }
        } catch (ModelNotFoundException $e) {
            // 第一次就立刻运行吧
            $interval = 0;
        }

        $time = Carbon::now()->addSecond($interval);

        dispatch((new MonitorJob($monitor))->delay($time)->onQueue('monitor'));
    }

    /**
     * 执行一个监控，返回一个监控快照
     *
     * @param Monitor $monitor
     *
     * @return array
     */
    static public function request(Monitor $monitor)
    {
        $curlHandle = curl_init();

        curl_setopt($curlHandle, CURLOPT_SAFE_UPLOAD, true); // 禁止body中使用@上传文件
        curl_setopt($curlHandle, CURLOPT_FORBID_REUSE, true); // 不重用TCP连接
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true); // 返回数据，不输出
        curl_setopt($curlHandle, CURLOPT_HEADER, true); // 输出header
        curl_setopt($curlHandle, CURLOPT_FOLLOWLOCATION, $monitor->request_follow_location); // 跟随跳转
        curl_setopt($curlHandle, CURLOPT_MAXREDIRS, 5); // 跳转次数

        curl_setopt($curlHandle, CURLOPT_URL, $monitor->request_url);

        if ($monitor->request_nobody) {
            curl_setopt($curlHandle, CURLOPT_NOBODY, true);
        }
        curl_setopt($curlHandle, CURLOPT_CUSTOMREQUEST, $monitor->request_method);
        if ($monitor->request_method != "GET") {
            curl_setopt($curlHandle, CURLOPT_POSTFIELDS, $monitor->request_body);
        }
        // CURLOPT_AUTOREFERER 重定向时，自动设置 header 中的Referer:信息。
        // CURLOPT_CONNECT_ONLY 仅连接

        // 最大60秒超时
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($curlHandle);

        $curlInfo = curl_getinfo($curlHandle);

        $curlErrorNo = curl_errno($curlHandle);
        $curlErrorMessage = curl_error($curlHandle);

        curl_close($curlHandle);

        $headerSize = $curlInfo['header_size'];
	    $curlInfo['headers'] = substr($response, 0, $headerSize);
	    $curlInfo['body'] = substr($response, $headerSize);
	    $curlInfo['curl_error_no'] = $curlErrorNo;
	    $curlInfo['curl_error_message'] = $curlErrorMessage;

	    return $curlInfo;
    }


	/**
	 * @param $snapshot Snapshot
	 * @param $requestResult array
	 *
	 * @return Snapshot
	 */
    static public function storeSnapshot($snapshot, $requestResult){
	    $snapshot->http_status_code = $requestResult['http_code'];
	    $snapshot->headers = $requestResult['headers'];
	    $snapshot->body_content = $requestResult['body'];

	    $snapshot->time_total = bcmul($requestResult['total_time'], 1000, 0);
	    $snapshot->time_dns = bcmul($requestResult['namelookup_time'], 1000, 0);
	    $snapshot->time_connection = bcmul($requestResult['connect_time'], 1000, 0);
	    $snapshot->time_transport = bcmul($requestResult['pretransfer_time'], 1000, 0);
	    $snapshot->error_message = "";
	    $snapshot->is_error = false;
	    $snapshot->is_match = false;

	    $curlErrorNo = $requestResult['curl_error_no'];
	    $curlErrorMessage = $requestResult['curl_error_message'];

	    if ($curlErrorNo !== 0) {
		    $snapshot->is_error = true;
		    $snapshot->error_message = "curl error[$curlErrorNo]: " . $curlErrorMessage;
            $snapshot->status_message = $snapshot->error_message;
	    } else {
		    $matcher = $snapshot->getMatcher();
		    /** @var $matcher Monitor\Match\MatchBase */
		    $snapshot->is_match = $matcher->isMatch();
		    if ($snapshot->is_match){
                $snapshot->status_message = $matcher->getMessage();
            }
	    }

        $snapshot->is_notice = true;
        // 取出上一个快照，判断是否变化，如果没变化，就直接return了
        try {
            /** @var Snapshot $perSnapshot */
            $perSnapshot = Snapshot::whereMonitorId($snapshot->monitor_id)->where('id', '<', $snapshot->id)->orderBy('id', 'desc')->firstOrFail();
            if ($perSnapshot->is_match == $snapshot->is_match && $perSnapshot->is_error == $snapshot->is_error) {
                // 如果匹配状态没变化，且错误状态没变化，就不通知
                $snapshot->status_level = 0;
                $snapshot->status_text = $perSnapshot->status_text;
                $snapshot->is_notice = false;
            }
        } catch (ModelNotFoundException $e) {
            // 如果第一次，且没有匹配也没错误，就不通知
            if (!$snapshot->is_match && !$snapshot->is_error) {
                $snapshot->status_level = 0;
                $snapshot->status_text = "New";
                $snapshot->is_notice = false;
            }
        }

        if ($snapshot->status_level !== 0){
            // 就4种状态
            // 常态 - 未匹配
            // 1 错误
            // 2 匹配命中 如果错误，肯定不匹配
            // 3 匹配恢复
            // 不存在的 上次命中，这次错误恢复，因为上次错误，上次就肯定是未匹配，这次不可能匹配恢复
            // 外加几种复合状态
            // 4 错误恢复，即上次错误，这次正常且未匹配，就通知错误恢复
            // 5 错误恢复匹配，即上次错误，这次错误恢复且匹配，就通知匹配

            if (!$snapshot->is_error && !$snapshot->is_match) {
                if ($perSnapshot->is_error){
                    // 4
                    $snapshot->status_text = "错误恢复";
                    $snapshot->status_level = 1;
                    $snapshot->status_message = "之前的错误已经消失，现在是正常未匹配状态";
                }else{
                    // 3
                    $snapshot->status_text = "未匹配";
                    $snapshot->status_level = 0;
                    $snapshot->status_message = "状态变更为未匹配状态";
                }
            } else {
                if ($snapshot->is_error) {
                    // 1
                    $snapshot->status_text = "请求错误";
                    $snapshot->status_level = 2;
                } else {
                    // 2、5
                    $snapshot->status_text = "匹配命中";
                    $snapshot->status_level = 1;
                }
            }
        }

        // 错误则未完成
        $snapshot->is_done = !$snapshot->is_error;
	    $snapshot->saveOrFail();
    }

	/**
	 * @param $monitor Monitor
	 * @param $snapshot Snapshot
	 */
    static public function updateMonitorData($monitor, $snapshot){
	    $monitorData = $monitor->data;
	    $monitorData->last_error = $snapshot->is_error;
	    $monitorData->last_match = $snapshot->is_match;
        $monitorData->last_status_level = $snapshot->status_level;
        $monitorData->last_status_text = $snapshot->status_text;

	    $nowTime = Carbon::now();

	    if ($monitorData->last_error) {
		    $monitorData->last_error_time = $nowTime;
	    }
	    if ($monitorData->last_match) {
		    $monitorData->last_match_time = $nowTime;
	    }

	    $monitorData->last_request_time = $nowTime;

	    $monitorData->time_total_average_15minute = $monitor->snapshots()->whereIsDone(1)->where('created_at', '>', Carbon::now()->subMinute(15))->avg('time_total');
	    $monitorData->time_total_average_30minute = $monitor->snapshots()->whereIsDone(1)->where('created_at', '>', Carbon::now()->subMinute(30))->avg('time_total');
	    $monitorData->time_total_average_1hour = $monitor->snapshots()->whereIsDone(1)->where('created_at', '>', Carbon::now()->subHour(1))->avg('time_total');
	    $monitorData->time_total_average_12hour = $monitor->snapshots()->whereIsDone(1)->where('created_at', '>', Carbon::now()->subHour(12))->avg('time_total');
	    $monitorData->time_total_average_24hour = $monitor->snapshots()->whereIsDone(1)->where('created_at', '>', Carbon::now()->subHour(24))->avg('time_total');

	    $monitorData->last_1hour_table_cache = json_encode($monitor->flotData());

	    $monitorData->saveOrFail();
    }
    /**
     * 决定要不要通知，包括通知文本的生成都在这里
     *
     * @param Snapshot $snapshot
     */
    static public function handleSnapshotNotice(Snapshot $snapshot)
    {
        /** @var User $user */
        $user = User::findOrFail($snapshot->monitor->project->user_id);

        $snapshot->is_notice && \Mail::to($user)->send(new MonitorNotice($snapshot));
    }
}