# 数据格式说明

本站的全部内容（朝代、中国节点、世界大事、中外联动、字典表）以 **JSON 文件**形式存放，
位于 `data/seed/`。格式定义与读写实现见 `inc/datapack.php`（导出与导入共用同一份表定义）。

## 目录结构

```
data/seed/
  manifest.json        版本、导出时间、各表行数、每个文件的 sha256
  categories.json      分类字典（16 个门类）
  regions.json         区域字典（11 个）
  relation_types.json  联动类型（因果 / 影响 / 对照）
  dynasties.json       朝代（25 个）
  cn_events.json       中国节点（117 条）
  world_events.json    世界大事（669 条）
  relations.json       中外联动（19 条）
```

每个文件的结构：

```json
{
  "table": "world_events",
  "natural_key": "title",
  "rows": [
    { "title": "…", "year": -221, "region": "west_asia", "category": "war" }
  ]
}
```

`rows` 也可以直接是一个裸数组 —— 手工编辑与第三方脚本生成都能被识别。

## 装载顺序

引用有方向，顺序不能乱：

```
categories → regions → relation_types → dynasties → cn_events → world_events → relations
```

## 三条约定

### 1. 不导出主键

`id` 是自增的，两次安装完全不同，导出来毫无意义还容易误导。
跨表引用一律用**自然键**：

| 引用 | 写法 |
|---|---|
| 中国节点 → 朝代 | `"dynasty": "tang"`（朝代 slug） |
| 联动 → 中国节点 | `"cn_ref": "标题原文"` |
| 联动 → 世界大事 | `"world_ref": "标题原文"` |
| 联动 → 类型 | `"type": "echo"`（类型 slug，存的就是 slug 本身） |

装载时按自然键查回 id 再写库。**代价**：改了标题就等于换了身份 ——
`relations` 里引用的标题必须与 `cn_events` / `world_events` 完全一致（校验器会逐条检查）。

### 2. 值为 null 的字段不写

```json
{ "title": "某条大事", "year": 1919 }        ← 没有 place / month / day，就是空
```

这样「无 → 有值」在 git diff 里表现为**新增一行**而不是改动一行，review 时能看清加了哪个字段。

### 3. 不含账号与时间戳

- `users`（含密码哈希）、`submissions`（投稿流转）**不在格式内**，
  任何导出/导入都不会碰它们。换站请重新注册管理员。
- `created_at` 等时间戳不导出，导入时由数据库重新生成 ——
  否则所有条目会显示成同一天。

## 值的写法

| 字段 | 约定 |
|---|---|
| `year` / `year_end` / `start_year` / `end_year` | **整数**。公元前为负数：公元前 221 年写 `-221`。不要写 `1919.0` 或 `"1919"` |
| `month` / `day` | 整数，可省略 |
| `importance` | 1~5。5=改变世界走向，1=参考 |
| `is_key` | 0 或 1，仅 `cn_events` 有 |
| `category` | 必须是 `categories.json` 里的某个 `slug` |
| `region` | 仅 `world_events` 有，同理对应 `regions.json` 的 `slug` |
| `dynasty` | `cn_events` 专有，朝代的 `slug` |

中文以 UTF-8 原样存放，不做 `\uXXXX` 转义；斜杠也不转义，URL 在文件里可直接阅读。

## 怎么改内容

**直接编辑 JSON，用任何文本编辑器都行，不限于 PHP。** 改完做两件事：

1. 本地校验：

   ```bash
   php tools/test_datapack.php
   ```

2. 装到站上（后台「导入」→ 选**合并**），
   或直接覆盖 `data/seed/` 后跑安装器。

### 加一条世界大事

在 `world_events.json` 的 `rows` 里追加一个对象：

```json
{
    "title": "某年发生了某事",
    "year": 1234,
    "region": "europe",
    "category": "science",
    "place": "威尼斯",
    "summary": "一句话概括，35~60 字。",
    "detail": "150~260 字。写清来龙去脉与为何重要，结尾点一句与中国的关联。",
    "figures": "人物甲、人物乙",
    "importance": 3
}
```

校验器会拒绝这些情况：标题重复、年份不是整数、`year_end` 早于 `year`、
`category` 不在字典里、引用的朝代/标题不存在。

## 导出

后台「导出」页（`admin/export.php`）可下载一份 ZIP，内容与 `data/seed/` 结构一致，
根部附 `README.txt` 说明与各文件 sha256，可随时核对是否被改动。

## 兼容性

格式带版本号（当前 `version: 1`）。导入时若遇到比自己新的 `version`，
应当先升级代码而不是硬读 —— 字段只会往后加，不会改语义。
