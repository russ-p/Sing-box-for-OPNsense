#!/bin/bash

echo -e ''
echo -e "\033[32m========Sing-box for OPNsense 一键卸载脚本=========\033[0m"
echo -e ''

# 定义颜色变量
GREEN="\033[32m"
YELLOW="\033[33m"
RED="\033[31m"
CYAN="\033[36m"
RESET="\033[0m"

# 定义配置文件和临时文件
CONFIG_FILE="/conf/config.xml"
TMP_FILE="/tmp/config.xml.tmp"

# 定义日志函数
log() {
    local color="$1"
    local message="$2"
    echo -e "${color}${message}${RESET}"
}

# 删除程序和配置
log "$YELLOW" "删除代理程序和配置，请稍等..."

# 停止服务
service singbox stop > /dev/null 2>&1

# 删除配置
rm -rf /usr/local/etc/sing-box

# 删除rc.d
rm -f /usr/local/etc/rc.d/sing-box

# 删除rc.conf
rm -f /etc/rc.conf.d/sing-box

# 删除action
rm -f /usr/local/opnsense/service/conf/actions.d/actions_sing-box.conf

# 删除inc
rm -f /usr/local/etc/inc/plugins.inc.d/sing_box.inc

# 删除php
rm -f /usr/local/www/services_sing_box.php
rm -f /usr/local/www/status_sing_box_logs.php
rm -f /usr/local/www/status_sing_box.php
rm -f /usr/local/www/services_sub.php
rm -f /usr/bin/sub


# 删除程序
rm -f /usr/local/bin/sing-box
echo ""

# 删除菜单和缓存
rm -rf /usr/local/opnsense/mvc/app/models/OPNsense/sing-box
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
rm -f /var/lib/php/tmp/opnsense_acl_cache.json

# 删除接口分配中的 tun_3000 / opt10
log "$YELLOW" "删除 TUN 接口配置，请稍等..."
if awk '
  BEGIN { in_interfaces=0; in_opt10=0; has_tun=0; removed=0 }
  /<interfaces>/ { in_interfaces=1 }
  /<\/interfaces>/ { in_interfaces=0 }
  /<opt10>/ {
    if (in_interfaces) {
      in_opt10=1
      block=$0 "\n"
      has_tun=0
      next
    }
  }
  {
    if (in_opt10) {
      block = block $0 "\n"
      if ($0 ~ /<if>tun_3000<\/if>/) {
        has_tun=1
      }
      if ($0 ~ /<\/opt10>/) {
        in_opt10=0
        if (has_tun) {
          removed=1
        } else {
          printf "%s", block
        }
        block=""
        has_tun=0
      }
      next
    }
    print
  }
  END { exit removed ? 0 : 1 }
' "$CONFIG_FILE" > "$TMP_FILE"; then
  mv "$TMP_FILE" "$CONFIG_FILE"
  log "$GREEN" "TUN 接口配置已删除"
else
  rm -f "$TMP_FILE"
  log "$CYAN" "未找到 tun_3000 接口配置，忽略"
fi

echo ""

# 删除 TUN 防火墙规则
log "$YELLOW" "删除 TUN 防火墙规则..."
if awk '
  BEGIN { in_rule=0; matched=0; rule_if=0; rule_descr=0 }
  /<rule[ >]/ {
    in_rule=1
    rule=$0 "\n"
    rule_if=0
    rule_descr=0
    next
  }
  {
    if (in_rule) {
      rule = rule $0 "\n"
      if ($0 ~ /<interface>opt10<\/interface>/) {
        rule_if=1
      }
      if ($0 ~ /<descr>Sing-Box TUN Allow All<\/descr>/ || $0 ~ /<description>Sing-Box TUN Allow All<\/description>/) {
        rule_descr=1
      }
      if ($0 ~ /<\/rule>/) {
        in_rule=0
        if (rule_if && rule_descr) {
          matched=1
        } else {
          printf "%s", rule
        }
        rule=""
      }
      next
    }
    print
  }
  END { exit matched ? 0 : 1 }
' "$CONFIG_FILE" > "$TMP_FILE"; then
  mv "$TMP_FILE" "$CONFIG_FILE"
  log "$GREEN" "TUN 防火墙规则已删除"
else
  rm -f "$TMP_FILE"
  log "$CYAN" "未找到 Sing-Box TUN 防火墙规则，跳过"
fi

echo ""

log "$YELLOW" "恢复 Unbound DNS 端口为 53..."
UNBOUND_STATE=$(awk '
BEGIN {
  in_unbound = 0
  in_general = 0
  has_53 = 0
  has_5355 = 0
}
{
  if ($0 ~ /<unboundplus[ >]/ || $0 ~ /<unbound[ >]/) in_unbound = 1
  if (in_unbound && $0 ~ /<general>/) in_general = 1

  if (in_unbound && in_general && $0 ~ /<port>53<\/port>/) has_53 = 1
  if (in_unbound && in_general && $0 ~ /<port>5355<\/port>/) has_5355 = 1

  if (in_unbound && $0 ~ /<\/general>/) in_general = 0
  if ($0 ~ /<\/unboundplus>/ || $0 ~ /<\/unbound>/) {
    in_unbound = 0
    in_general = 0
  }
}
END {
  if (has_5355) {
    print "need_fix"
  } else if (has_53) {
    print "already_ok"
  } else {
    print "not_found"
  }
}
' "$CONFIG_FILE")

if [ "$UNBOUND_STATE" = "already_ok" ]; then
  log "$CYAN" "Unbound DNS 端口已经为 53，跳过"
elif [ "$UNBOUND_STATE" = "not_found" ]; then
  log "$CYAN" "未找到 Unbound DNS 端口配置，跳过"
else
  awk '
  BEGIN {
    in_unbound = 0
    in_general = 0
    replaced = 0
  }
  {
    if ($0 ~ /<unboundplus[ >]/ || $0 ~ /<unbound[ >]/) in_unbound = 1
    if (in_unbound && $0 ~ /<general>/) in_general = 1

    if (in_unbound && in_general && $0 ~ /<port>5355<\/port>/ && replaced == 0) {
      sub(/<port>5355<\/port>/, "<port>53</port>")
      replaced = 1
    }

    print

    if (in_unbound && $0 ~ /<\/general>/) in_general = 0
    if ($0 ~ /<\/unboundplus>/ || $0 ~ /<\/unbound>/) {
      in_unbound = 0
      in_general = 0
    }
  }
  END {
    if (replaced == 0) exit 1
  }
  ' "$CONFIG_FILE" > "$TMP_FILE"

  if [ $? -eq 0 ] && [ -s "$TMP_FILE" ]; then
    mv "$TMP_FILE" "$CONFIG_FILE"
    log "$GREEN" "Unbound DNS 端口已恢复为 53"
  else
    rm -f "$TMP_FILE"
    log "$RED" "恢复 Unbound DNS 端口失败，请检查配置文件"
  fi
fi
# 重启所有服务
log "$YELLOW" "重新应用所有更改，请稍等..."
if /usr/local/etc/rc.reload_all >/dev/null 2>&1; then
    log "$GREEN" "系统配置重载完成"
else
    log "$RED" "系统配置重载失败"
fi

if service configd restart > /dev/null 2>&1; then
    log "$GREEN" "configd 重启完成"
else
    log "$RED" "configd 重启失败"
fi

if configctl unbound restart > /dev/null 2>&1; then
    log "$GREEN" "Unbound DNS 重启完成"
else
    log "$RED" "Unbound DNS 重启失败"
fi

if configctl filter reload > /dev/null 2>&1; then
    log "$GREEN" "防火墙规则重新加载完成"
else
    log "$RED" "防火墙规则重新加载失败"
fi

# 完成提示
log "$GREEN" "卸载完成，Sing-Box 相关文件、TUN 接口和防火墙规则已清理。"
echo ""