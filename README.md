# Typecho-Plugin-Heatmap

[![License](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Typecho](https://img.shields.io/badge/Typecho-1.3+-green.svg)](https://typecho.org)

> 显示最近一年文章发布的热力图

## 使用方法

在需要显示的模板位置，添加以下代码

```php
<?php \TypechoPlugin\Heatmap\Plugin::output(); ?>
# 或者直接传入配色名称和显示语言，支持配色和语言请查看后台
<?php \TypechoPlugin\Heatmap\Plugin::output($colorScheme, $labelLang); ?>
```