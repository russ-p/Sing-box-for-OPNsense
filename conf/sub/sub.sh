#!/bin/sh
# Clash 转 Sing-Box 在线订阅转换脚本

#################### 初始化 ####################
Server_Dir=$(cd "$(dirname "$0")" && pwd)
[ -f "$Server_Dir/env" ] && . "$Server_Dir/env"

API_BASE="https://subconverters.com/sub"
ENCODED_URL=$(printf "%s" "$CLASH_URL" | jq -s -R -r @uri)

Conf_Dir="$Server_Dir/conf"
Temp_Dir="$Server_Dir/temp"
mkdir -p "$Conf_Dir" "$Temp_Dir"

TMP_SINGBOX=$(mktemp "$Temp_Dir/sub.json")
TMP_TEMPLATE=$(mktemp "$Temp_Dir/template.json")
OUTPUT_FILE="/usr/local/etc/sing-box/sub/conf/config.json"

# 清理上次失败残留文件
rm -f "$Temp_Dir/sub.json" "$Temp_Dir/template.json" "$Conf_Dir/config.json"

# 订阅地址校验
[ -z "$CLASH_URL" ] && {
    echo "错误：未设置 CLASH_URL 环境变量"
    exit 1
}

# 构造 Sing-Box 转换地址
API_URL="${API_BASE}?target=singbox&url=${ENCODED_URL}&insert=false&emoji=true&list=false&expand=true"

echo ""
echo "下载转换配置..."
echo ""

if curl -L -k -sS --retry 2 -m 15 -o "$TMP_SINGBOX" "$API_URL"; then
    echo "下载成功：$TMP_SINGBOX"
    echo ""
    
else
    echo "下载失败！"
    echo ""
    exit 1
fi

# 验证 JSON 合法性
if ! jq empty "$TMP_SINGBOX" >/dev/null 2>&1; then
    echo "错误：下载的配置不是有效 JSON："
    cat "$TMP_SINGBOX"
    exit 1
fi

# 写入到目标目录
echo "写入配置..."
echo ""

# 模板路径
TEMPLATE_FILE="/usr/local/etc/sing-box/sub/template.json"

# 验证模板存在
if [ ! -f "$TEMPLATE_FILE" ]; then
    echo "模板文件不存在：$TEMPLATE_FILE"
    exit 1
fi

# 预处理模板：去掉 BOM 与常见注释，避免 jq 解析失败
perl -0777 -pe 's/^\x{EF}\x{BB}\x{BF}//; s#(?s)/\*.*?\*/##g; s#(^|\s)//.*\$##mg; s#(^|\s)\#.*\$##mg' \
    "$TEMPLATE_FILE" > "$TMP_TEMPLATE"

# 验证模板 JSON 合法性
if ! jq empty "$TMP_TEMPLATE" >/dev/null 2>&1; then
    echo "错误：模板文件不是有效 JSON：$TEMPLATE_FILE"
    echo "已预处理后的临时模板：$TMP_TEMPLATE"
    exit 1
fi

if ! jq -s '
  def is_node:
    (.type != "direct" and .type != "block" and .type != "dns" and .type != "blackhole" and .type != "selector" and .type != "urltest");

  . as $docs |
  (($docs[1].outbounds // []) | map(select(is_node))) as $nodes |
  $docs[0] * {
    outbounds: (
      [
        {
          tag: "select",
          type: "selector",
          outbounds: (["auto"] + ($nodes | map(.tag)))
        },
        {
          tag: "auto",
          type: "urltest",
          url: "https://www.gstatic.com/generate_204",
          interval: "3m",
          tolerance: 150,
          interrupt_exist_connections: true,
          outbounds: ($nodes | map(.tag))
        },
        {
          tag: "direct",
          type: "direct"
        }
      ] + $nodes
    )
  }
' "$TMP_TEMPLATE" "$TMP_SINGBOX" > "$OUTPUT_FILE"; then
    echo "错误：合并模板与订阅配置失败。"
    echo "模板临时文件：$TMP_TEMPLATE"
    echo "订阅临时文件：$TMP_SINGBOX"
    exit 1
fi

# 验证生成后的配置
if ! jq empty "$OUTPUT_FILE" >/dev/null 2>&1; then
    echo "错误：生成后的配置不是有效 JSON：$OUTPUT_FILE"
    exit 1
fi

# 复制配置文件到主目录
echo "替换配置..."
cp "$OUTPUT_FILE" /usr/local/etc/sing-box/config.json
echo ""

# 静默重启 sing-box
echo "重启sing-box..."
service sing-box restart >/dev/null 2>&1
echo ""

# 删除临时配置文件
echo "删除临时文件..."
rm -f "$Temp_Dir/sub.json" "$Temp_Dir/template.json" "$Conf_Dir/config.json"
echo ""

#仪表盘信息
LAN_IP=$(ifconfig 2>/dev/null | awk '/inet / && $2 != "127.0.0.1" {print $2; exit}')
[ -z "$LAN_IP" ] && LAN_IP="127.0.0.1"
echo "仪表盘访问地址: http://${LAN_IP}:9090/ui"
# 避免未配置 IPv6 的接口在 ifconfig 枚举时输出 ioctl(SIOCGIFINFO_IN6) 告警
echo ""