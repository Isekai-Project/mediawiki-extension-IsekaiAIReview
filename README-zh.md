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