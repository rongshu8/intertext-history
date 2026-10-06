# 贡献指南

感谢你愿意花时间完善这个项目。

## 报Issue

**纠错优先于提新功能。** 这个项目的内容是 AI 生成后人工整理的，
年代与史实错误很常见 —— 你发现一处，就是Others 少踩一次坑。

请尽量附上：

- 涉及的页面 URL 与节点/事件标题
- 你认为正确的年代或说法，**以及依据**（史料出处、教材页码、学术论文）
- 截图（如果是显示问题）

标题里带上节点名，例如：`纠错：怛罗斯之战 751 年，但页面写 750年`

## 提 Pull Request

### 分支

```
git checkout -b fix/751-battle-year
```

### 改内容 vs 改代码

**内容纠错**（改年代、改标题、补联动）走后台或`data/seed/*.json`，**不需要动代码**。

**代码改动**前请先读 [`docs/开发指南.md`](docs/开发指南.md)，
那里记了三条硬规则（安装闸门顺序、URL helper、`base_path()`），
违反其中任何一条都会在子目录部署或全新安装时出问题，而且**本地测不出来**。

### 提交前必跑

```bash
# 1. PHP 语法
for f in $(git ls-files '*.php'); do php -l "$f" | grep -v '^No syntax'; done

# 2. 页面依赖链 + 安装闸门顺序
php tools/smoke_pages.php

# 3. SQL 拼接与括号平衡（改过任何 INSERT/UPDATE 后必跑）
python tools/check_sql_quotes.py

# 4. 数据包质量
php tools/test_datapack.php

# 5. 凭据自检（提交前必跑）
python tools/check_repo_secrets.py
```

第 3 条查的错误 **`php -l` 查不出来** —— 字符串里 SQL 写错、
括号少一个，对 PHP 语法完全合法，只有真跑才会炸。

改过前端样式的话，另外跑：

```bash
python tools/verify_responsive.py https://your-site.example.com/
```

### 别做的事

- ❌ **不要提交 `config.local.php`** —— 它含数据库密码，已在 `.gitignore` 里
- ❌ **不要在代码里写死域名、密码、API key** —— 一律走环境变量
- ❌ **不要在文档里写生产环境的服务器地址与账号**
- ❌ **不要引入 Composer / npm / 构建步骤 / CDN 外部依赖** —— 这个项目的定位就是「上传即用」
- ❌ **不要用 PHP 8.x 语法**（`str_contains` `match` `?->` `enum` `readonly` 构造器提升）——
  代码要兼容 PHP 7.4

## 内容准则

- **中外并列仅表示大致同期，不代表因果关系。** 真正有因果或结构性呼应的，
  请用「联动」功能标注（并在 `note` 里说明理由）
- 年代采用通说；早期断代存在学术分歧时，取主流说法并在 detail 里注明
- 涉及近现代史，以中国现行中学历史教材的通行口径为基础
- 不确定的内容宁可不加，也不要写错

## 许可

提交的代码与内容以 MIT 发布。