# Isekai AI Review
[English](README.md)

这个扩展必须和Moderation扩展一起用。

通过AI审核的编辑会自动在Moderation中通过审核。

如果你想要增加新的API接口，可以提交issue给我。

## 使用方法
先在阿里云注册：[https://www.aliyun.com/product/lvwang](https://www.aliyun.com/product/lvwang)

然后安装Moderation扩展：[https://github.com/edwardspec/mediawiki-moderation](https://github.com/edwardspec/mediawiki-moderation)

安装composer包：（如果你是在release页面下载的，可以忽略这一项）
```php
composer update
```

在```LocalSettings.php```中添加相关配置：
```php
wfLoadExtension('IsekaiAIReview');

//配置部分
$wgAIReviewEndpoint = 'cn-shanghai';
$wgAIReviewAccessKeyId = '阿里云的Access key id';
$wgAIReviewAccessKeySecret = '阿里云的Access key secret';
$wgAIReviewBizType = 'isekaiwiki';
$wgAIReviewRobotUID = 0; //在Moderation里显示的，执行审核操作的机器人账号的UID
```

## 多版本提交链

创建页面时（当前公开审核基线为 `0`），标题与累计新增正文合并为一次审核请求，格式为 `《完整可读标题》`、一个空行、正文内容。标题包含命名空间和子页面路径。长正文的每个分段都携带标题，每段总长度不超过 10000 个 UTF-8 字符；空正文也审核标题。尚未批准的新建页面后续提交继续采用该格式，已有公开基线的编辑审核累计新增正文。

移动页面审核目标完整标题。通过后执行现有移动批准流程；不通过保持待审；服务异常由 Job 重试。AI 返回后从主库重新锁定并核对移动记录，人工已处理或目标已改变的记录不会应用旧结果。移动审核日志与结果 Hook 使用目标标题。

配合本仓库 Moderation 的多版本实现，每次审核绑定独立 `temp_revision_id` 和正文 hash。Job 依序读取快照，审核与已批准公开基线比较得到的累计新增文本。全部 AI 判断失败时继续待审；存在通过项时，连续处理到最后通过项，前面的版本按 AI 判断通过或拒绝。后面的失败项继续待审。异常和不完整服务响应保存为 `error`，重新调度。

一个 Job 最多处理 20 个快照，审核循环按 60 秒预算执行，每次 AI 请求的超时不超过当前剩余预算；随后完成已审核前缀的发布与任务交接。运行租约为 300 秒。旧 worker 租约失效后不能写入判断或发布。重复排队由 MW 去重辅助处理，数据库租约负责互斥。

先按 Moderation 的 [迁移说明](../Moderation/REVISION_CHAINS.md)运行 updater，再启动 Job runner。支持延迟任务的队列会安排周期性恢复。默认数据库队列不支持延迟任务：页面访问提供恢复唤醒，生产环境还应每分钟运行以下命令，保证无访问时仍能恢复丢失或超时任务：

```sh
php extensions/IsekaiAIReview/maintenance/RecoverAIReview.php
```

维护命令只恢复投递登记，AI 调用由正常 Job runner 执行。服务错误至少等待 60 秒再恢复投递。Job 参数兼容旧的 `mod_id`。

`IsekaiAIReviewResult` 保留原有前五个参数，在末尾追加 `temp_revision_id` 和 `stage`。`judgment` 表示 AI 判断，`publication` 表示统一审核服务实际完成的处理结果；使用此 Hook 的扩展应根据阶段处理，避免对一次版本重复发送通知。
