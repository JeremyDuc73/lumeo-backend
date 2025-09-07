#!/usr/bin/env bash
set -euo pipefail

# Quick end-to-end backend test for:
# - Auth (register + JWT login)
# - Service creation and publication (SQL helper)
# - Purchase flow (creates reservation + conversation)
# - Messaging in a conversation
# - Mercure SSE events for conversation topic (message.created)
#
# Configuration via env vars (override when calling):
#   API_BASE=http://localhost:8000 \
#   HUB_BASE=http://localhost:9090 \
#   bash scripts/test-back.sh

API_BASE=${API_BASE:-http://localhost:8000}
HUB_BASE=${HUB_BASE:-http://localhost:9090}
# Global toggle (legacy): SKIP_PUBLISH applies to message step if specific toggles are not set
SKIP_PUBLISH=${SKIP_PUBLISH:-1}
# Specific toggles
SKIP_PUBLISH_PURCHASE=${SKIP_PUBLISH_PURCHASE:-1}
SKIP_PUBLISH_MESSAGE=${SKIP_PUBLISH_MESSAGE:-$SKIP_PUBLISH}
# Extended Mercure tests toggles
# 1) Test seller notifications during purchase (expects reservation.created)
TEST_SELLER_NOTIFS_ON_PURCHASE=${TEST_SELLER_NOTIFS_ON_PURCHASE:-1}
# 2) Test buyer notifications when seller sends a message (expects message.created)
TEST_BUYER_NOTIFS_ON_SELLER_MESSAGE=${TEST_BUYER_NOTIFS_ON_SELLER_MESSAGE:-1}
# 3) Optional: Test seller notifications when buyer replies (expects message.created)
TEST_SELLER_NOTIFS_ON_BUYER_REPLY=${TEST_SELLER_NOTIFS_ON_BUYER_REPLY:-0}
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")"/.. && pwd)"
cd "$PROJECT_ROOT"

# Curl options (add -k if using HTTPS in local dev with self-signed certs)
API_CURL_OPTS="-fsS"
API_CURL_OPTS_NOFAIL="-sS" # like API_CURL_OPTS but without -f to capture error bodies
HUB_CURL_OPTS="-fsS"
HUB_CURL_TLS=""
case "$API_BASE" in
  https://*) API_CURL_OPTS="$API_CURL_OPTS -k"; API_CURL_OPTS_NOFAIL="$API_CURL_OPTS_NOFAIL -k" ;;
esac
case "$HUB_BASE" in
  https://*) HUB_CURL_OPTS="$HUB_CURL_OPTS -k"; HUB_CURL_TLS="-k" ;;
esac

# ------------- helpers -------------
msg() { echo -e "\n[+] $*\n"; }
err() { echo -e "\n[!] $*\n" >&2; }
require_bin() { command -v "$1" >/dev/null 2>&1 || { err "Missing dependency: $1"; exit 1; }; }
json_field() { jq -r "$1" 2>/dev/null || echo null; }

wait_for_log() {
  local file=$1 pattern=$2 timeout=${3:-10}
  local start now
  start=$(date +%s)
  while true; do
    if grep -qE "$pattern" "$file" 2>/dev/null; then
      return 0
    fi
    now=$(date +%s)
    if (( now - start >= timeout )); then
      return 1
    fi
    sleep 0.5
  done
}

cleanup() {
  for pidvar in SSE_CONV_PID SSE_SELLER_NOTIF_PID SSE_BUYER_NOTIF_PID SSE_SELLER_REPLY_PID; do
    pidval=${!pidvar:-}
    if [[ -n "$pidval" ]] && kill -0 "$pidval" 2>/dev/null; then
      kill "$pidval" || true
    fi
  done
}
trap cleanup EXIT

# ------------- checks -------------
require_bin curl
require_bin jq
require_bin php

msg "Using API_BASE=$API_BASE, HUB_BASE=$HUB_BASE"
echo "SKIP_PUBLISH_PURCHASE=$SKIP_PUBLISH_PURCHASE (1=skip Mercure during purchase)"
echo "SKIP_PUBLISH_MESSAGE=$SKIP_PUBLISH_MESSAGE (1=skip Mercure during message)"
echo "TEST_SELLER_NOTIFS_ON_PURCHASE=$TEST_SELLER_NOTIFS_ON_PURCHASE (expect reservation.created)"
echo "TEST_BUYER_NOTIFS_ON_SELLER_MESSAGE=$TEST_BUYER_NOTIFS_ON_SELLER_MESSAGE (expect message.created)"
echo "TEST_SELLER_NOTIFS_ON_BUYER_REPLY=$TEST_SELLER_NOTIFS_ON_BUYER_REPLY (expect message.created)"

msg "Checking Mercure hub health..."
if curl $HUB_CURL_OPTS "$HUB_BASE/healthz" >/dev/null; then
  echo "Mercure hub is healthy."
else
  err "Mercure hub not healthy or unreachable at $HUB_BASE/healthz. Proceeding anyway."
fi

msg "Checking API connectivity (/api/services)..."
if curl $API_CURL_OPTS "$API_BASE/api/services" >/dev/null; then
  echo "API reachable."
else
  err "API not reachable at $API_BASE. Please start Symfony server (symfony serve -d) or PHP builtin."; exit 1
fi

# ------------- test data -------------
# random suffix for emails to avoid collisions
# Avoid pipefail + SIGPIPE (from head) causing the pipeline to be considered failed
set +o pipefail
SUFFIX=$(LC_ALL=C tr -dc 'a-z0-9' </dev/urandom | head -c 6)
set -o pipefail
SELLER_EMAIL="seller_${SUFFIX}@example.com"
BUYER_EMAIL="buyer_${SUFFIX}@example.com"
PASSWORD="password"
# Prepare query strings per step
if [[ "$SKIP_PUBLISH_PURCHASE" == "1" ]]; then PURCHASE_QS='?skipPublish=1'; else PURCHASE_QS=''; fi
if [[ "$SKIP_PUBLISH_MESSAGE" == "1" ]]; then MESSAGE_QS='?skipPublish=1'; else MESSAGE_QS=''; fi

msg "Registering users (seller and buyer)..."
curl $API_CURL_OPTS -X POST "$API_BASE/api/register_check" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"$SELLER_EMAIL\",\"password\":\"$PASSWORD\"}" >/dev/null || true

curl $API_CURL_OPTS -X POST "$API_BASE/api/register_check" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"$BUYER_EMAIL\",\"password\":\"$PASSWORD\"}" >/dev/null || true

echo "Created: $SELLER_EMAIL and $BUYER_EMAIL"

msg "Logging in (JWT)..."
SELLER_JWT=$(curl $API_CURL_OPTS -L -X POST "$API_BASE/api/login_check" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"$SELLER_EMAIL\",\"password\":\"$PASSWORD\"}" | json_field '.token')

BUYER_JWT=$(curl $API_CURL_OPTS -L -X POST "$API_BASE/api/login_check" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"$BUYER_EMAIL\",\"password\":\"$PASSWORD\"}" | json_field '.token')

if [[ -z "$SELLER_JWT" || "$SELLER_JWT" == "null" ]]; then err "Failed to get seller JWT"; exit 1; fi
if [[ -z "$BUYER_JWT" || "$BUYER_JWT" == "null" ]]; then err "Failed to get buyer JWT"; exit 1; fi

echo "Seller JWT: ${#SELLER_JWT} chars"
echo "Buyer  JWT: ${#BUYER_JWT} chars"

# Fetch seller and buyer profile IDs (needed for notifications topics)
SELLER_PROFILE_JSON=$(curl $API_CURL_OPTS -H "Authorization: Bearer $SELLER_JWT" "$API_BASE/api/myprofile")
SELLER_ID=$(echo "$SELLER_PROFILE_JSON" | json_field '.id')
if [[ -z "$SELLER_ID" || "$SELLER_ID" == "null" ]]; then err "Failed to fetch seller profile id. Response: $SELLER_PROFILE_JSON"; exit 1; fi

BUYER_PROFILE_JSON=$(curl $API_CURL_OPTS -H "Authorization: Bearer $BUYER_JWT" "$API_BASE/api/myprofile")
BUYER_ID=$(echo "$BUYER_PROFILE_JSON" | json_field '.id')
if [[ -z "$BUYER_ID" || "$BUYER_ID" == "null" ]]; then err "Failed to fetch buyer profile id. Response: $BUYER_PROFILE_JSON"; exit 1; fi

echo "Seller Profile ID: $SELLER_ID"
echo "Buyer  Profile ID: $BUYER_ID"

msg "Creating a service as seller (cost=0)..."
SERVICE_JSON=$(curl $API_CURL_OPTS -X POST "$API_BASE/api/services" \
  -H "Authorization: Bearer $SELLER_JWT" \
  -H 'Content-Type: application/json' \
  -d '{"title":"Coaching 30min","cost":0,"description":"Session rapide de test","isRemote":true}')

SERVICE_ID=$(echo "$SERVICE_JSON" | json_field '.id')
if [[ -z "$SERVICE_ID" || "$SERVICE_ID" == "null" ]]; then err "Failed to create service. Response: $SERVICE_JSON"; exit 1; fi

echo "Service ID: $SERVICE_ID"

msg "Publishing the service via SQL (status -> published)..."
php bin/console doctrine:query:sql "UPDATE service SET status = 'published' WHERE id = $SERVICE_ID" >/dev/null

msg "Verifying public service listing..."
SERVICES_LIST=$(curl $API_CURL_OPTS "$API_BASE/api/services")
if echo "$SERVICES_LIST" | jq -e ".[] | select(.id == $SERVICE_ID)" >/dev/null; then
  echo "Service is visible in public listing."
else
  err "Service not found in public listing. Response: $SERVICES_LIST"
fi

PURCHASE_QS_EFFECTIVE="$PURCHASE_QS"
if [[ "$TEST_SELLER_NOTIFS_ON_PURCHASE" == "1" ]]; then
  # Force publish during purchase so we can receive reservation.created
  PURCHASE_QS_EFFECTIVE=''
  # Start SSE on seller notifications to capture reservation.created
  SSE_SELLER_NOTIF_LOG="$PROJECT_ROOT/var/sse-notifications-seller-$SELLER_ID.log"
  rm -f "$SSE_SELLER_NOTIF_LOG"
  msg "Starting SSE subscription to SELLER notifications before purchase (log: $SSE_SELLER_NOTIF_LOG)"
  set +e
  SELLER_TOPIC_URL="https://lumeo.app/profiles/$SELLER_ID/notifications"
  curl $HUB_CURL_TLS --no-progress-meter -i -N -G \
    "$HUB_BASE/.well-known/mercure" \
    --data-urlencode "topic=$SELLER_TOPIC_URL" \
    -H 'Accept: text/event-stream' > "$SSE_SELLER_NOTIF_LOG" 2>&1 &
  SSE_SELLER_NOTIF_PID=$!
  set -e
  sleep 1
fi

msg "Purchasing the service as buyer with initial message (should create reservation + conversation)..."
PURCHASE_JSON=$(curl $API_CURL_OPTS_NOFAIL -X POST "$API_BASE/api/services/$SERVICE_ID/purchase$PURCHASE_QS_EFFECTIVE" \
  -H "Authorization: Bearer $BUYER_JWT" \
  -H 'Content-Type: application/json' \
  -d '{"message":"Bonjour, dispo cette aprem ?"}')

CONV_ID=$(echo "$PURCHASE_JSON" | json_field '.conversation.id')
if [[ -z "$CONV_ID" || "$CONV_ID" == "null" ]]; then err "Failed to purchase or retrieve conversation. Response: $PURCHASE_JSON"; exit 1; fi

echo "Conversation ID: $CONV_ID"

# If we subscribed seller notifications before purchase, wait for reservation.created
if [[ "$TEST_SELLER_NOTIFS_ON_PURCHASE" == "1" ]]; then
  if wait_for_log "$SSE_SELLER_NOTIF_LOG" 'reservation\.created' 15; then
    echo "SSE received: reservation.created (seller notifications)"
  else
    err "Timeout waiting for seller notifications reservation.created. Check $SSE_SELLER_NOTIF_LOG"
  fi
fi

# ------------- Mercure SSE test (conversation topic) -------------
SSE_CONV_LOG="$PROJECT_ROOT/var/sse-conversation-$CONV_ID.log"
mkdir -p "$PROJECT_ROOT/var"
rm -f "$SSE_CONV_LOG"
if [[ "$SKIP_PUBLISH_MESSAGE" != "1" ]]; then
  msg "Starting SSE subscription to conversation topic in background... (log: $SSE_CONV_LOG)"
  set +e
  TOPIC_URL="https://lumeo.app/conversations/$CONV_ID"
  # Use URL-encoded topic and include response headers; disable progress meter for clean logs
  curl $HUB_CURL_TLS --no-progress-meter -i -N -G \
    "$HUB_BASE/.well-known/mercure" \
    --data-urlencode "topic=$TOPIC_URL" \
    -H 'Accept: text/event-stream' > "$SSE_CONV_LOG" 2>&1 &
  SSE_CONV_PID=$!
  set -e
  sleep 1

  # Optionally subscribe buyer notifications before seller sends a message
  if [[ "$TEST_BUYER_NOTIFS_ON_SELLER_MESSAGE" == "1" ]]; then
    SSE_BUYER_NOTIF_LOG="$PROJECT_ROOT/var/sse-notifications-buyer-$BUYER_ID.log"
    rm -f "$SSE_BUYER_NOTIF_LOG"
    msg "Starting SSE subscription to BUYER notifications before seller message (log: $SSE_BUYER_NOTIF_LOG)"
    set +e
    BUYER_TOPIC_URL="https://lumeo.app/profiles/$BUYER_ID/notifications"
    curl $HUB_CURL_TLS --no-progress-meter -i -N -G \
      "$HUB_BASE/.well-known/mercure" \
      --data-urlencode "topic=$BUYER_TOPIC_URL" \
      -H 'Accept: text/event-stream' > "$SSE_BUYER_NOTIF_LOG" 2>&1 &
    SSE_BUYER_NOTIF_PID=$!
    set -e
    sleep 1
  fi

  msg "Sending a new message in the conversation as seller (should trigger message.created)..."
  SEND_MSG_JSON=$(curl $API_CURL_OPTS_NOFAIL -X POST "$API_BASE/api/conversations/$CONV_ID/messages$MESSAGE_QS" \
    -H "Authorization: Bearer $SELLER_JWT" \
    -H 'Content-Type: application/json' \
    -d '{"content":"Oui, 15h me convient."}')

  if wait_for_log "$SSE_CONV_LOG" 'message\.created' 10; then
    echo "SSE received: message.created"
  else
    err "Timeout waiting for SSE message.created. Check $SSE_CONV_LOG"
  fi

  # If buyer notifications SSE is active, expect a message.created notification as well
  if [[ "$TEST_BUYER_NOTIFS_ON_SELLER_MESSAGE" == "1" ]]; then
    if wait_for_log "$SSE_BUYER_NOTIF_LOG" 'message\.created' 10; then
      echo "SSE received: message.created (buyer notifications)"
    else
      err "Timeout waiting for buyer notifications message.created. Check $SSE_BUYER_NOTIF_LOG"
    fi
  fi
else
  echo "Skipping SSE test and Mercure publishes for message (SKIP_PUBLISH_MESSAGE=1)."
  msg "Sending a new message in the conversation as seller (no publish)..."
  SEND_MSG_JSON=$(curl $API_CURL_OPTS_NOFAIL -X POST "$API_BASE/api/conversations/$CONV_ID/messages$MESSAGE_QS" \
    -H "Authorization: Bearer $SELLER_JWT" \
    -H 'Content-Type: application/json' \
    -d '{"content":"Oui, 15h me convient."}')
fi

# Optional: Test seller notifications when buyer replies
if [[ "$TEST_SELLER_NOTIFS_ON_BUYER_REPLY" == "1" ]]; then
  SSE_SELLER_REPLY_LOG="$PROJECT_ROOT/var/sse-notifications-seller-reply-$SELLER_ID.log"
  rm -f "$SSE_SELLER_REPLY_LOG"
  msg "Starting SSE subscription to SELLER notifications before buyer reply (log: $SSE_SELLER_REPLY_LOG)"
  set +e
  SELLER_TOPIC_URL="https://lumeo.app/profiles/$SELLER_ID/notifications"
  curl $HUB_CURL_TLS --no-progress-meter -i -N -G \
    "$HUB_BASE/.well-known/mercure" \
    --data-urlencode "topic=$SELLER_TOPIC_URL" \
    -H 'Accept: text/event-stream' > "$SSE_SELLER_REPLY_LOG" 2>&1 &
  SSE_SELLER_REPLY_PID=$!
  set -e
  sleep 1

  msg "Sending a reply in the conversation as buyer (should trigger message.created notification to seller)..."
  BUYER_REPLY_JSON=$(curl $API_CURL_OPTS_NOFAIL -X POST "$API_BASE/api/conversations/$CONV_ID/messages$MESSAGE_QS" \
    -H "Authorization: Bearer $BUYER_JWT" \
    -H 'Content-Type: application/json' \
    -d '{"content":"Réponse acheteur -> notif vendeur"}')

  if wait_for_log "$SSE_SELLER_REPLY_LOG" 'message\.created' 10; then
    echo "SSE received: message.created (seller notifications on buyer reply)"
  else
    err "Timeout waiting for seller notifications message.created (buyer reply). Check $SSE_SELLER_REPLY_LOG"
  fi
fi

msg "Fetching conversation details to verify persistence..."
CONV_SHOW=$(curl $API_CURL_OPTS -H "Authorization: Bearer $BUYER_JWT" "$API_BASE/api/conversations/$CONV_ID")
MESSAGES_COUNT=$(echo "$CONV_SHOW" | jq -r '.messages | length' 2>/dev/null || echo 0)
echo "Messages in conversation: $MESSAGES_COUNT"

msg "DONE. Summary:"
echo "  Seller: $SELLER_EMAIL"
echo "  Buyer : $BUYER_EMAIL"
echo "  Service ID: $SERVICE_ID"
echo "  Conversation ID: $CONV_ID"
echo "  SSE conversation log: $SSE_CONV_LOG"
if [[ "${SSE_SELLER_NOTIF_LOG:-}" != "" ]]; then echo "  SSE seller-notifications (purchase) log: $SSE_SELLER_NOTIF_LOG"; fi
if [[ "${SSE_BUYER_NOTIF_LOG:-}" != "" ]]; then echo "  SSE buyer-notifications (seller message) log: $SSE_BUYER_NOTIF_LOG"; fi
if [[ "${SSE_SELLER_REPLY_LOG:-}" != "" ]]; then echo "  SSE seller-notifications (buyer reply) log: $SSE_SELLER_REPLY_LOG"; fi

echo "\nYou can also manually test SSE in a terminal:"
echo "  curl -N \"$HUB_BASE/.well-known/mercure?topic=https://lumeo.app/conversations/$CONV_ID\""
echo "  # Notifications topics:" 
echo "  curl -N \"$HUB_BASE/.well-known/mercure?topic=https://lumeo.app/profiles/$SELLER_ID/notifications\"  # seller"
echo "  curl -N \"$HUB_BASE/.well-known/mercure?topic=https://lumeo.app/profiles/$BUYER_ID/notifications\"   # buyer"
