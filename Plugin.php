<?php

namespace TypechoPlugin\Heatmap;

use Typecho\Db;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Radio;
use Widget\Base\Contents;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 文章发布热力图，展示当前年份全年发布记录
 *
 * @package  Heatmap
 * @author   shingchi
 * @version  1.0.0
 * @link     https://github.com/shingchi/Typecho-Plugin-Heatmap
 */
class Plugin implements PluginInterface
{
    // 配色方案
    private static $palettes = [
        'green'  => ['#ebedf0', '#9be9a8', '#40c463', '#30a14e', '#216e39'],
        'blue'   => ['#ebedf0', '#bae6ff', '#58aef4', '#1d84d4', '#0550ae'],
        'purple' => ['#ebedf0', '#d8b4fe', '#a855f7', '#7c3aed', '#4c1d95'],
        'orange' => ['#ebedf0', '#fed7aa', '#fb923c', '#ea580c', '#9a3412'],
    ];

    public static function activate(): void {}
    public static function deactivate(): void {}

    /**
     * 获取插件配置面板
     *
     * @param Form $form 配置面板
     */
    public static function config(Form $form): void
    {
        $colorScheme = new Radio(
            'colorScheme',
            [
                'green'  => '绿色 ' . self::getPaletteHtml(self::$palettes['green']),
                'blue'   => '蓝色 ' . self::getPaletteHtml(self::$palettes['blue']),
                'purple' => '紫色 ' . self::getPaletteHtml(self::$palettes['purple']),
                'orange' => '橙色 ' . self::getPaletteHtml(self::$palettes['orange']),
            ],
            'green',
            '配色方案',
            '选择热力图的配色方案'
        );
        $form->addInput($colorScheme);

        $labelLang = new Radio(
            'labelLang',
            [
                'en' => '英文 (Jan / Mon)',
                'zh' => '中文 (一月 / 一)',
            ],
            'en',
            '标签语言',
            '月份与星期等标签的显示语言'
        );
        $form->addInput($labelLang);
    }

    /**
     * 生成配色方案 HTML 字符串
     *
     * @param array $colors 颜色数组
     * @return string 颜色 HTML HTML 字符串
     */
    private static function getPaletteHtml($colors): string
    {
        $html = '';

        foreach ($colors as $key => $color) {
            $html .= '<span style="display:inline-block;width:10px;height:10px;margin-right:0;background:' . $color . '"></span>';
        }

        return $html;
    }

    public static function personalConfig(Form $form): void {}

    /**
     * 直接输出热力图 HTML
     * 模板调用示例：\TypechoPlugin\Heatmap\Plugin::output();
     *
     * @param string|null $colorScheme 覆盖后台配色设置（可选）
     */
    public static function output(?string $colorScheme = null, ?string $labelLang = null): void
    {
        echo self::render($colorScheme, $labelLang);
    }

    /**
     * 返回热力图 HTML 字符串
     *
     * @param string|null $colorScheme 覆盖后台配色设置（可选）
     * @param string|null $labelLang   覆盖后台标签语言设置（可选）
     */
    private static function render(?string $colorScheme = null, ?string $labelLang = null): string
    {
        // 1. 读取插件配置
        $options       = Options::alloc();
        $pluginOptions = $options->plugin('Heatmap');
        $cfgScheme     = $pluginOptions->colorScheme ?? 'green';
        $cfgLabelLang  = $pluginOptions->labelLang     ?? 'en';

        $colorScheme   = $colorScheme ?? $cfgScheme;
        $labelLang     = $labelLang ?? $cfgLabelLang;

        // 2. 配色方案
        $colors = self::$palettes[$colorScheme] ?? self::$palettes['green'];

        // 3. 时间范围：最近一年（今天往前推 364 天，共 365 天）
        // $today      = strtotime(date('Y-m-d'));          // 今天 00:00:00
        // 从配置中获取时间戳
        $today      = strtotime(date('Y-m-d', $options->time)); // 今天 00:00:00
        $rangeEnd   = $today + 86399;                           // 今天 23:59:59
        $rangeStart = $today - 364 * 86400;                     // 365 天前 00:00:00

        // 4. 查询数据库：当年所有已发布文章
        $db   = Db::get();
        $posts = Contents::alloc();
        $query = $db->select()
               ->from('table.contents')
               ->where('type = ?', 'post')
               ->where('status = ?', 'publish')
               ->where('created >= ?', $rangeStart)
               ->where('created <= ?', $rangeEnd)
               ->order('created', Db::SORT_ASC);
        $db->fetchAll($query, [$posts, 'push']);
        // 计算总文章数
        $totalCount = $posts->size($query);
        $jsTotalLabels = ($labelLang === 'zh')
            ? json_encode('共 ' . $totalCount . ' 篇')
            : json_encode('Total of ' . $totalCount . ' articles');

        // 按日期分组：counts 用于颜色深度，articles 用于 tooltip 内容
        $counts   = [];
        $articles = [];
        while ($posts->next()) {
            $day = date('Y-m-d', $posts->created);
            $counts[$day] = ($counts[$day] ?? 0) + 1;
            $articles[$day][] = [
                'title' => $posts->title,
                'url'   => $posts->permalink,
            ];
        }
        $maxCount = $counts ? max($counts) : 1;

        // 5. 构建日历网格
        $startDow  = (int)date('w', $rangeStart);
        $gridStart = $rangeStart - $startDow * 86400;

        $endDow  = (int)date('w', $today);
        $gridEnd = $today + (6 - $endDow) * 86400;

        $weeks = [];
        $cur   = $gridStart;
        while ($cur <= $gridEnd) {
            $week = [];
            for ($d = 0; $d < 7; $d++) {
                $week[] = $cur;
                $cur   += 86400;
            }
            $weeks[] = $week;
        }

        // 6. 月份标签（每月在网格中第一次出现的列打标签）
        // $zhMonths = ['一月','二月','三月','四月','五月','六月','七月','八月','九月','十月','十一月','十二月'];
        $zhMonths = ['一', '二', '三', '四', '五', '六', '七', '八', '九', '十', '十一', '十二'];
        $monthLabels = [];
        $prevMonth   = -1;
        foreach ($weeks as $wi => $week) {
            foreach ($week as $ts) {
                if ($ts >= $rangeStart && $ts <= $today) {
                    $m = (int)date('n', $ts);
                    if ($m !== $prevMonth) {
                        // $monthLabels[$wi] = date('M', $ts);
                        $monthLabels[$wi] = ($labelLang === 'zh')
                            ? $zhMonths[$m - 1]
                            : date('M', $ts);
                        $prevMonth        = $m;
                    }
                    break;
                }
            }
        }

        // 7. 颜色映射
        $colorMap = [];
        foreach ($weeks as $wi => $week) {
            foreach ($week as $di => $ts) {
                $dateStr = date('Y-m-d', $ts);
                $cnt     = $counts[$dateStr] ?? 0;
                if ($cnt === 0) {
                    $color = $colors[0];
                } else {
                    $r = $cnt / $maxCount;
                    if ($r <= 0.25)      $color = $colors[1];
                    elseif ($r <= 0.50)  $color = $colors[2];
                    elseif ($r <= 0.75)  $color = $colors[3];
                    else                 $color = $colors[4];
                }
                $isOutRange = ($ts < $rangeStart || $ts > $today);
                // 范围外格子用白色；范围内无未来格子
                $colorMap[$wi][$di] = [
                    'date'      => $dateStr,
                    'count'     => $cnt,
                    'color'     => $isOutRange ? 'transparent' : $color,
                    'outOfYear' => $isOutRange,
                    'future'    => false,
                ];
            }
        }

        // 8. 布局常量（传给 JS）
        $cellSize   = 10;
        $cellGap    = 3;
        $step       = $cellSize + $cellGap;
        $lblWidth   = 28;
        $topOffset  = 20;
        $legendH    = 24;   // 图例行高度
        $canvasW    = $lblWidth + count($weeks) * $step;
        $canvasH    = $topOffset + 7 * $step + $legendH;

        // 9. 序列化数据供 JS 使用
        $jsArticles  = json_encode(
            $articles,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        $jsColorMap  = json_encode($colorMap);
        $jsMonthLbls = json_encode($monthLabels);
        $jsColors    = json_encode($colors);
        // 行标签：英文显示 Mon/Wed/Fri，中文显示 一/三/五
        $jsRowLabels = ($labelLang === 'zh')
            ? json_encode([1 => '一', 3 => '三', 5 => '五'])
            : json_encode([1 => 'Mon', 3 => 'Wed', 5 => 'Fri']);
        $jsLegendLess = json_encode($labelLang === 'zh' ? '少' : 'Less');
        $jsLegendMore = json_encode($labelLang === 'zh' ? '多' : 'More');
        $jsLegendY   = $topOffset + 7 * $step + (int)round($legendH / 2) + 2; // 图例基准 Y

        $uid = 'hm' . substr(md5(uniqid('', true)), 0, 8);

        // 10. 拼装 HTML
        ob_start();
?>
<div class="heatmap-wrap" id="<?= $uid ?>_wrap">

  <canvas
    id="<?= $uid ?>"
    width="<?= $canvasW ?>"
    height="<?= $canvasH ?>"
    style="display:block;cursor:default;"
  ></canvas>

  <!-- Tooltip 浮层 -->
  <div id="<?= $uid ?>_tip" style="display:none;position:absolute;z-index:9999;background:#fff;border:0 solid #ffd0b6;border-radius:4px;box-shadow:rgba(0, 0, 0, 0.2) 1px 2px 10px;padding:5px 10px;min-width:160px;max-width:280px;white-space:nowrap;border-color: rgb(255, 208, 182);pointer-events:auto;box-sizing:border-box;"></div>
</div>

<style>
.heatmap-wrap{position:relative;display:flex;justify-content:center;padding-top:16px;padding-bottom:10px;border: 1px solid #d1d9e0;border-top-left-radius: 6px;border-top-right-radius: 6px;}
#<?= $uid ?>_tip{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:14px;line-height:1.6;color:#24292f;}
#<?= $uid ?>_tip .hm-date{color:#57606a;font-size:12px;}
#<?= $uid ?>_tip ul{margin:0;padding:0;list-style:none;}
#<?= $uid ?>_tip ul li{list-style:none;position:relative;}
/* #<?= $uid ?>_tip ul li::before{content:'·';position:absolute;left:0;color:#57606a;} */
/* #<?= $uid ?>_tip ul li a{color:#0969da;text-decoration:none;word-break:break-all;} */
/* #<?= $uid ?>_tip ul li a:hover{text-decoration:underline;} */
</style>

<script>
(function(){
  /* 数据 */
  var articles   = <?= $jsArticles ?>;
  var colorMap   = <?= $jsColorMap ?>;
  var monthLabels= <?= $jsMonthLbls ?>;
  var colors     = <?= $jsColors ?>;

  /* 布局常量 */
  var CELL     = <?= $cellSize ?>;
  var STEP     = <?= $step ?>;
  var LBL_W    = <?= $lblWidth ?>;
  var TOP      = <?= $topOffset ?>;
  var WEEKS    = <?= count($weeks) ?>;
  var LEGEND_Y = <?= $jsLegendY ?>;

  var rowLabels  = <?= $jsRowLabels ?>;
  var legendLess = <?= $jsLegendLess ?>;
  var legendMore = <?= $jsLegendMore ?>;
  var totalLabels = <?= $jsTotalLabels ?>;

  /* Canvas 绘制 */
  var canvas = document.getElementById('<?= $uid ?>');
  var ctx    = canvas.getContext('2d');

  /* 设备像素比，让高分屏不模糊 */
  var dpr    = window.devicePixelRatio || 1;
  var cssW   = canvas.width;
  var cssH   = canvas.height;
  canvas.width  = cssW * dpr;
  canvas.height = cssH * dpr;
  canvas.style.width  = cssW + 'px';
  canvas.style.height = cssH + 'px';
  ctx.scale(dpr, dpr);

  ctx.font = '12px -apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif';

  /* 月份标签 */
  ctx.fillStyle = '#57606a';
  ctx.textAlign  = 'left';
  ctx.textBaseline = 'middle';
  for (var wi in monthLabels) {
    ctx.fillText(monthLabels[wi], LBL_W + parseInt(wi, 10) * STEP, 8);
  }

  /* 行标签 Mon / Wed / Fri */
  ctx.textAlign = 'right';
  for (var ri in rowLabels) {
    var ry = TOP + parseInt(ri, 10) * STEP + CELL / 2;
    ctx.fillText(rowLabels[ri], LBL_W - 4, ry);
  }

  /* 圆角矩形工具 */
  function roundRect(x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y,     x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x,     y + h, r);
    ctx.arcTo(x,     y + h, x,     y,     r);
    ctx.arcTo(x,     y,     x + w, y,     r);
    ctx.closePath();
  }

  /* 热力方块 */
  for (var wi2 = 0; wi2 < WEEKS; wi2++) {
    var week = colorMap[wi2];
    if (!week) continue;
    for (var di = 0; di < 7; di++) {
      var cell = week[di];
      if (!cell) continue;
      var cx = LBL_W + wi2 * STEP;
      var cy = TOP   + di  * STEP;
      ctx.globalAlpha = cell.outOfYear ? 1 : (cell.future ? 0.35 : 1);
      ctx.fillStyle   = cell.color;
      roundRect(cx, cy, CELL, CELL, 2);
      ctx.fill();
    }
  }
  ctx.globalAlpha = 1;

  /* 图例（右对齐，绘制在格子下方） */
  (function(){
    var GAP      = 4;   // 色块间距
    var txtGap   = 5;   // 文字与色块间距
    ctx.font         = '12px -apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif';
    ctx.textBaseline = 'middle';
    ctx.fillStyle    = '#57606a';

    /* 计算整体宽度，从右侧往左排 */
    var duoW  = ctx.measureText(legendMore).width;
    var shaoW = ctx.measureText(legendLess).width;
    /* 5 个色块 + 4 个间距 + "少" + "多" + 2 个 txtGap */
    var totalW = shaoW + txtGap + 5 * CELL + 4 * GAP + txtGap + duoW;
    var canvasCSS = parseInt(canvas.style.width, 10);
    var padding = 15;   // 图例行与边框间距
    var startX = canvasCSS - totalW - padding;   // 右对齐起始 X

    /* 左侧：共 x 篇 */
    ctx.textAlign = 'left';
    ctx.fillText(totalLabels, padding, LEGEND_Y);

    /* "少" */
    ctx.textAlign = 'left';
    ctx.fillText(legendLess, startX, LEGEND_Y);
    var x = startX + shaoW + txtGap;

    /* 5 个色块 */
    for (var i = 0; i < colors.length; i++) {
      ctx.globalAlpha = 1;
      ctx.fillStyle   = colors[i];
      roundRect(x, LEGEND_Y - CELL / 2, CELL, CELL, 2);
      ctx.fill();
      x += CELL + (i < colors.length - 1 ? GAP : 0);
    }

    /* "多" */
    x += txtGap;
    ctx.fillStyle = '#57606a';
    ctx.fillText(legendMore, x, LEGEND_Y);
  })();
  var tip    = document.getElementById('<?= $uid ?>_tip');
  var hideT  = null;
  var overCell = false;   // 鼠标是否在有文章的格子上
  var overTip  = false;   // 鼠标是否在 tooltip 内

  function fmt(s) {
    var p = s.split('-');
    return p[0] + ' 年 ' + parseInt(p[1], 10) + ' 月 ' + parseInt(p[2], 10) + ' 日';
  }

  function esc(s) {
    return String(s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  /* 只有 overCell 和 overTip 都为 false 时才真正隐藏 */
  function tryHide() {
    if (!overCell && !overTip) {
      tip.style.display = 'none';
    }
  }

  function schedulHide() {
    clearTimeout(hideT);
    hideT = setTimeout(tryHide, 600);
  }

  function showTip(cell, clientX, clientY) {
    clearTimeout(hideT);
    var posts = articles[cell.date] || [];
    if (!posts.length) { overCell = false; schedulHide(); return; }

    var h = '<div class="hm-date">' + fmt(cell.date) + '</div><ul>';
    for (var i = 0; i < posts.length; i++) {
      h += '<li><a href="' + posts[i].url + '" target="_blank" rel="noopener">'
         + esc(posts[i].title) + '</a></li>';
    }
    h += '</ul>';
    tip.innerHTML     = h;
    tip.style.display = 'block';
    moveTip(clientX, clientY);
  }

  function moveTip(clientX, clientY) {
    var off = 14;
    var wrap    = canvas.parentElement;
    var wrapRect= wrap.getBoundingClientRect();
    var canRect = canvas.getBoundingClientRect();
    /* 鼠标相对 wrap 的偏移（wrap 是 position:relative 的容器）*/
    var mx = clientX - wrapRect.left;
    var my = clientY - wrapRect.top;
    var tw = tip.offsetWidth, th = tip.offsetHeight;
    var ww = wrap.offsetWidth;
    // /* 默认右上方 */
    // var x = mx + off;
    // var y = my - th - off;
    // /* 右侧超出 wrap 则移到左侧 */
    // if (x + tw + 4 > ww) x = mx - tw - off;
    // /* 上方超出则移到下方 */
    // if (y < 4) y = my + off;
    // if (x < 4) x = 4;
    /* 始终在右上方：x 向右偏移，y 向上偏移 */
    var x = mx + off;
    var y = my - th - off;
    /* 右侧超出 wrap 则改到左侧 */
    if (x + tw + 4 > ww) x = mx - tw - off;
    /* x 仍不够则贴左边 */
    if (x < 4) x = 4;
    /* y 超出顶部则贴顶，不回落到鼠标下方 */
    // if (y < 0) y = 0;
    tip.style.left = x + 'px';
    tip.style.top  = y + 'px';
  }

  /* 命中检测：将鼠标坐标映射回方块 */
  function hitCell(e) {
    var rect = canvas.getBoundingClientRect();
    var mx   = e.clientX - rect.left;
    var my   = e.clientY - rect.top;

    var wi = Math.floor((mx - LBL_W) / STEP);
    var di = Math.floor((my - TOP)   / STEP);
    if (wi < 0 || wi >= WEEKS || di < 0 || di >= 7) return null;

    var cx = LBL_W + wi * STEP;
    var cy = TOP   + di * STEP;
    if (mx < cx || mx > cx + CELL || my < cy || my > cy + CELL) return null;

    var week = colorMap[wi];
    return (week && week[di]) ? week[di] : null;
  }

  canvas.addEventListener('mousemove', function(e) {
    var cell = hitCell(e);
    if (cell && (articles[cell.date] || []).length > 0) {
      canvas.style.cursor = 'pointer';
      overCell = true;
      clearTimeout(hideT);
      showTip(cell, e.clientX, e.clientY);
    } else {
      canvas.style.cursor = 'default';
      overCell = false;
      schedulHide();
    }
  });

  canvas.addEventListener('mouseleave', function() {
    overCell = false;
    schedulHide();
  });

  tip.addEventListener('mouseenter', function() {
    overTip = true;
    clearTimeout(hideT);
  });

  tip.addEventListener('mouseleave', function() {
    overTip = false;
    schedulHide();
  });
})();
</script>
<?php
        return ob_get_clean() ?: '';
    }
}
