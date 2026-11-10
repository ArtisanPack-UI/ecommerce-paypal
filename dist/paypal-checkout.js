const SDK_URL = "https://www.paypal.com/sdk/js";
const APPLE_PAY_SDK_URL = "https://applepay.cdn-apple.com/jsapi/1.latest/apple-pay-sdk.js";
const GOOGLE_PAY_SDK_URL = "https://pay.google.com/gp/p/js/pay.js";
const sdkLoads = /* @__PURE__ */ new Map();
const scriptLoads = /* @__PURE__ */ new Map();
let extraNamespaces = 0;
function optionsOf(config) {
  return config && "object" === typeof config.options && null !== config.options ? config.options : {};
}
function list(value) {
  const values = Array.isArray(value) ? value : "string" === typeof value ? value.split(",") : [];
  return values.map((item) => String(item).trim()).filter((item) => "" !== item);
}
function orderIdOf(config) {
  return String(optionsOf(config).order_id || config?.client_secret || "");
}
function sdkParams(config, components = []) {
  const options = optionsOf(config);
  const params = new URLSearchParams();
  const wanted = [.../* @__PURE__ */ new Set([...list(options.components), ...list(components)])].sort();
  params.set("client-id", String(config?.publishable_key || ""));
  params.set("components", (wanted.length ? wanted : ["buttons"]).join(","));
  params.set("currency", String(options.currency || "USD").toUpperCase());
  params.set("intent", "authorize" === options.intent ? "authorize" : "capture");
  params.set("commit", "true");
  if (options.locale) {
    params.set("locale", String(options.locale));
  }
  if (list(options.enable_funding).length) {
    params.set("enable-funding", list(options.enable_funding).join(","));
  }
  if (list(options.disable_funding).length) {
    params.set("disable-funding", list(options.disable_funding).join(","));
  }
  if ("sandbox" === options.mode && options.buyer_country) {
    params.set("buyer-country", String(options.buyer_country).toUpperCase());
  }
  return params;
}
function sdkUrl(config, components = []) {
  return `${SDK_URL}?${sdkParams(config, components).toString()}`;
}
function loadKey(config) {
  const params = sdkParams(config);
  const options = optionsOf(config);
  params.delete("components");
  return [params.toString(), options.user_id_token || "", options.partner_attribution_id || ""].join("|");
}
function cspNonce(override = "") {
  if (override) {
    return String(override);
  }
  const nonced = document.querySelector("script[nonce]");
  return nonced ? String(nonced.nonce || nonced.getAttribute("nonce") || "") : "";
}
function appendScript(src, attributes = {}) {
  return new Promise((resolve, reject) => {
    const script = document.createElement("script");
    const nonce = cspNonce(attributes.nonce);
    script.src = src;
    script.async = true;
    if (nonce) {
      script.nonce = nonce;
      script.setAttribute("nonce", nonce);
    }
    for (const [name, value] of Object.entries(attributes)) {
      if ("nonce" === name) {
        continue;
      }
      script.setAttribute(name, value);
    }
    script.addEventListener("load", () => resolve());
    script.addEventListener("error", () => {
      script.remove();
      reject(new Error(`Could not load ${src.split("?")[0]}`));
    });
    document.head.appendChild(script);
  });
}
function loadScript(src) {
  if (!scriptLoads.has(src)) {
    scriptLoads.set(src, appendScript(src).catch((error) => {
      scriptLoads.delete(src);
      throw error;
    }));
  }
  return scriptLoads.get(src);
}
function loadSdk(config, { components = [] } = {}) {
  const key = loadKey(config);
  const wanted = new Set(sdkParams(config, components).get("components").split(","));
  const existing = sdkLoads.get(key);
  if (existing && [...wanted].every((component) => existing.components.has(component))) {
    return existing.promise;
  }
  const merged = /* @__PURE__ */ new Set([...existing ? existing.components : [], ...wanted]);
  const namespace = existing || [...sdkLoads.values()].length ? `paypal_ap_${++extraNamespaces}` : "paypal";
  const options = optionsOf(config);
  const attrs = {};
  if ("paypal" !== namespace) {
    attrs["data-namespace"] = namespace;
  }
  if (options.user_id_token) {
    attrs["data-user-id-token"] = String(options.user_id_token);
  }
  if (options.partner_attribution_id) {
    attrs["data-partner-attribution-id"] = String(options.partner_attribution_id);
  }
  const nonce = cspNonce(options.csp_nonce);
  if (nonce) {
    attrs.nonce = nonce;
    attrs["data-csp-nonce"] = nonce;
  }
  const entry = { components: merged, namespace, promise: null };
  entry.promise = appendScript(sdkUrl(config, [...merged]), attrs).then(() => {
    const sdk = window[namespace];
    if (!sdk) {
      throw new Error("The PayPal SDK did not load.");
    }
    return sdk;
  }).catch((error) => {
    if (sdkLoads.get(key) === entry) {
      if (existing) {
        sdkLoads.set(key, existing);
      } else {
        sdkLoads.delete(key);
      }
    }
    throw error;
  });
  sdkLoads.set(key, entry);
  return entry.promise;
}
function resetSdk() {
  sdkLoads.clear();
  scriptLoads.clear();
  extraNamespaces = 0;
}
function call(callback, ...args) {
  return "function" === typeof callback ? callback(...args) : void 0;
}
async function mountButtons(container, config, callbacks = {}) {
  const paypal = await loadSdk(config, { components: ["buttons"] });
  const options = optionsOf(config);
  const buttons = paypal.Buttons({
    style: options.button || {},
    onClick: (data) => call(callbacks.onClick, data),
    createOrder: () => orderIdOf(config),
    onApprove: async (data, actions) => {
      const result = await call(callbacks.onApprove, data);
      if (result && result.restart && actions && "function" === typeof actions.restart) {
        return actions.restart();
      }
      return void 0;
    },
    onCancel: (data) => call(callbacks.onCancel, data),
    onError: (error) => call(callbacks.onError, error)
  });
  if ("function" === typeof buttons.isEligible && !buttons.isEligible()) {
    call(callbacks.onIneligible);
    return { eligible: false, close: () => {
    } };
  }
  await buttons.render(container);
  return { eligible: true, close: () => call(buttons.close?.bind(buttons)) };
}
function resolveColor(value, element) {
  if (!value) {
    return "";
  }
  const probe = document.createElement("span");
  probe.style.color = value;
  probe.style.display = "none";
  element.appendChild(probe);
  const computed = window.getComputedStyle(probe).color || value;
  probe.remove();
  const canvas = document.createElement("canvas");
  let context = null;
  try {
    context = canvas.getContext?.("2d", { willReadFrequently: true }) ?? null;
  } catch {
    context = null;
  }
  if (!context) {
    return computed;
  }
  canvas.width = 1;
  canvas.height = 1;
  context.fillStyle = computed;
  context.fillRect(0, 0, 1, 1);
  const [red, green, blue, alpha] = context.getImageData(0, 0, 1, 1).data;
  return 255 === alpha ? `rgb(${red}, ${green}, ${blue})` : `rgba(${red}, ${green}, ${blue}, ${(alpha / 255).toFixed(2)})`;
}
function cardFieldStyle(element) {
  const computed = window.getComputedStyle(element);
  const token = (name, fallback) => resolveColor(computed.getPropertyValue(name).trim() || fallback, element);
  const color = token("--color-base-content", computed.color || "#1f2937");
  const font = computed.fontFamily || "system-ui, sans-serif";
  return {
    input: { color, "font-family": font, "font-size": "16px", padding: "0.75rem" },
    ".invalid": { color: token("--color-error", "#b91c1c") },
    ":focus": { color }
  };
}
function titleFrame(container) {
  const frame = container.querySelector("iframe");
  const label = container.getAttribute("aria-labelledby");
  if (frame && label) {
    frame.title = document.getElementById(label)?.textContent?.trim() || frame.title;
  }
}
async function mountCardFields(fields, config, callbacks = {}) {
  const paypal = await loadSdk(config, { components: ["card-fields"] });
  const first = Object.values(fields).find(Boolean);
  const card = paypal.CardFields({
    style: callbacks.style || (first ? cardFieldStyle(first) : {}),
    createOrder: () => orderIdOf(config),
    onApprove: (data) => call(callbacks.onApprove, data),
    onError: (error) => call(callbacks.onError, error),
    inputEvents: {
      onChange: (state) => call(callbacks.onChange, state),
      onBlur: (state) => call(callbacks.onBlur || callbacks.onChange, state)
    }
  });
  if (!card.isEligible()) {
    call(callbacks.onIneligible);
    return { eligible: false, submit: async () => void 0, getState: async () => void 0, close: () => {
    } };
  }
  const renderers = { name: "NameField", number: "NumberField", expiry: "ExpiryField", cvv: "CVVField" };
  const rendered = [];
  for (const [field, method] of Object.entries(renderers)) {
    if (fields[field]) {
      const instance = card[method]();
      rendered.push(instance);
      await instance.render(fields[field]);
      titleFrame(fields[field]);
    }
  }
  return {
    eligible: true,
    submit: (submitOptions) => card.submit(submitOptions),
    getState: async () => "function" === typeof card.getState ? card.getState() : void 0,
    close: () => rendered.forEach((instance) => call(instance.close?.bind(instance)))
  };
}
async function mountApplePay(container, config, callbacks = {}) {
  const ApplePay = window.ApplePaySession;
  if (!ApplePay || !ApplePay.canMakePayments?.()) {
    call(callbacks.onIneligible);
    return { eligible: false, close: () => {
    } };
  }
  const [paypal] = await Promise.all([loadSdk(config, { components: ["applepay"] }), loadScript(APPLE_PAY_SDK_URL)]);
  const applepay = paypal.Applepay();
  const settings = await applepay.config();
  if (!settings?.isEligible) {
    call(callbacks.onIneligible);
    return { eligible: false, close: () => {
    } };
  }
  const options = optionsOf(config);
  const button = document.createElement("apple-pay-button");
  button.setAttribute("buttonstyle", "black");
  button.setAttribute("type", "buy");
  button.setAttribute("locale", String(options.locale || "en_US").replace("_", "-"));
  button.addEventListener("click", () => {
    const orderId = orderIdOf(config);
    const session = new ApplePay(4, {
      countryCode: settings.countryCode,
      merchantCapabilities: settings.merchantCapabilities,
      supportedNetworks: settings.supportedNetworks,
      currencyCode: String(options.currency || "USD").toUpperCase(),
      requiredBillingContactFields: ["postalAddress"],
      total: { label: callbacks.label || "Total", amount: String(options.amount || "0.00"), type: "final" }
    });
    session.onvalidatemerchant = (event) => {
      applepay.validateMerchant({ validationUrl: event.validationURL }).then((result) => session.completeMerchantValidation(result.merchantSession)).catch((error) => {
        session.abort();
        call(callbacks.onError, error);
      });
    };
    session.onpaymentauthorized = async (event) => {
      let completed = false;
      try {
        await applepay.confirmOrder({ orderId, token: event.payment.token, billingContact: event.payment.billingContact });
        session.completePayment(ApplePay.STATUS_SUCCESS);
        completed = true;
        await call(callbacks.onApprove, { orderID: orderId });
      } catch (error) {
        if (!completed) {
          try {
            session.completePayment(ApplePay.STATUS_FAILURE);
          } catch {
          }
        }
        call(callbacks.onError, error);
      }
    };
    session.oncancel = () => call(callbacks.onCancel, { orderID: orderId });
    session.begin();
  });
  container.appendChild(button);
  return { eligible: true, close: () => button.remove() };
}
async function mountGooglePay(container, config, callbacks = {}) {
  const [paypal] = await Promise.all([loadSdk(config, { components: ["googlepay"] }), loadScript(GOOGLE_PAY_SDK_URL)]);
  const googlepay = paypal.Googlepay();
  const settings = await googlepay.config();
  if (!settings || false === settings.isEligible) {
    call(callbacks.onIneligible);
    return { eligible: false, close: () => {
    } };
  }
  const options = optionsOf(config);
  const client = new window.google.payments.api.PaymentsClient({ environment: "live" === options.mode ? "PRODUCTION" : "TEST" });
  const base = { apiVersion: settings.apiVersion, apiVersionMinor: settings.apiVersionMinor };
  const ready = await client.isReadyToPay({ ...base, allowedPaymentMethods: settings.allowedPaymentMethods });
  if (!ready?.result) {
    call(callbacks.onIneligible);
    return { eligible: false, close: () => {
    } };
  }
  const button = client.createButton({
    buttonType: "pay",
    buttonSizeMode: "fill",
    buttonLocale: String(options.locale || "en").slice(0, 2),
    onClick: async () => {
      const orderId = orderIdOf(config);
      try {
        const data = await client.loadPaymentData({
          ...base,
          allowedPaymentMethods: settings.allowedPaymentMethods,
          merchantInfo: settings.merchantInfo,
          transactionInfo: {
            countryCode: settings.countryCode,
            currencyCode: String(options.currency || "USD").toUpperCase(),
            totalPriceStatus: "FINAL",
            totalPrice: String(options.amount || "0.00")
          }
        });
        const result = await googlepay.confirmOrder({ orderId, paymentMethodData: data.paymentMethodData });
        if ("PAYER_ACTION_REQUIRED" === result?.status) {
          await googlepay.initiatePayerAction({ orderId });
        }
        await call(callbacks.onApprove, { orderID: orderId });
      } catch (error) {
        if ("CANCELED" === error?.statusCode) {
          call(callbacks.onCancel, { orderID: orderId });
        } else {
          call(callbacks.onError, error);
        }
      }
    }
  });
  container.appendChild(button);
  return { eligible: true, close: () => button.remove() };
}
async function mountMessages(container, config, settings = {}) {
  const paypal = await loadSdk(config, { components: ["messages"] });
  const options = optionsOf(config);
  const render = async (amount) => {
    await paypal.Messages({
      amount: String(amount),
      currency: String(options.currency || "USD").toUpperCase(),
      placement: settings.placement || "payment",
      style: settings.style || { layout: "text" },
      onRender: () => call(settings.onRender)
    }).render(container);
  };
  await render(settings.amount ?? options.amount ?? "0.00");
  return { update: (amount) => render(amount) };
}
export {
  APPLE_PAY_SDK_URL,
  GOOGLE_PAY_SDK_URL,
  SDK_URL,
  cardFieldStyle,
  cspNonce,
  loadScript,
  loadSdk,
  mountApplePay,
  mountButtons,
  mountCardFields,
  mountGooglePay,
  mountMessages,
  orderIdOf,
  resetSdk,
  sdkParams,
  sdkUrl
};
