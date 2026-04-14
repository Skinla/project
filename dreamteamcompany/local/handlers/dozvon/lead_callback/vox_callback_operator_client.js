/**
 * Callback scenario template for Voximplant.
 *
 * Expected script_custom_data JSON:
 * {
 *   "lead_id": 123,
 *   "attempt_number": 1,
 *   "client_number": "+79991234567",
 *   "sip_line": "sip58",
 *   "sip_password": "secret",
 *   "operator_user_id": 17,
 *   "operator_extension": "101",
 *   "operator_name": "Ivan Operator",
 *   "portal_host": "bitrix.example.ru",
 *   "operator_destination": "",
 *   "operator_destination_type": "user"
 * }
 *
 * By default the script dials the selected employee through callUserDirect().
 * If your portal routes calls by SIP extension, replace startOperatorLeg()
 * with a SIP-based implementation and pass operator_destination/operator_destination_type.
 */

require(Modules.Logger);

var operatorCall = null;
var clientCall = null;
var bridgeStarted = false;
var finished = false;
var data = parseCustomData();

VoxEngine.addEventListener(AppEvents.Started, function () {
    try {
        validateData(data);
    } catch (error) {
        Logger.write('callback invalid custom data: ' + error.message);
        VoxEngine.terminate();
        return;
    }

    Logger.write('callback started lead=' + data.lead_id + ' attempt=' + data.attempt_number + ' operator=' + data.operator_user_id);
    operatorCall = startOperatorLeg(data);
    attachOperatorHandlers(operatorCall);
});

function parseCustomData() {
    try {
        return JSON.parse(VoxEngine.customData() || '{}');
    } catch (error) {
        Logger.write('callback customData parse error: ' + error.message);
        return {};
    }
}

function validateData(payload) {
    if (!payload.client_number) {
        throw new Error('client_number is required');
    }
    if (!payload.operator_user_id && !payload.operator_destination && !payload.operator_extension) {
        throw new Error('operator target is required');
    }
    if (!payload.sip_line) {
        throw new Error('sip_line is required');
    }
}

function startOperatorLeg(payload) {
    Logger.write('callback operator leg start user=' + payload.operator_user_id + ' ext=' + (payload.operator_extension || ''));

    if (payload.operator_destination_type === 'sip' && payload.operator_destination) {
        Logger.write('callback operator leg via SIP destination=' + payload.operator_destination);
        return VoxEngine.callSIP(payload.operator_destination, payload.sip_line, payload.sip_password);
    }

    if (payload.operator_destination_type === 'user' && payload.operator_destination) {
        Logger.write('callback operator leg via direct user destination=' + payload.operator_destination);
        return VoxEngine.callUserDirect(String(payload.operator_destination), payload.sip_line);
    }

    return VoxEngine.callUserDirect(String(payload.operator_user_id), payload.sip_line);
}

function attachOperatorHandlers(call) {
    call.addEventListener(CallEvents.Connected, function () {
        Logger.write('callback operator answered');
        clientCall = VoxEngine.callPSTN(data.client_number, data.sip_line);
        attachClientHandlers(clientCall);
    });

    call.addEventListener(CallEvents.Failed, function (event) {
        finishWithError('operator_no_answer', extractReason(event));
    });

    call.addEventListener(CallEvents.Disconnected, function () {
        if (!bridgeStarted) {
            finishWithError('operator_no_answer', 'operator_disconnected_before_bridge');
            return;
        }
        finishGracefully('connected', 'operator_disconnected_after_bridge');
    });
}

function attachClientHandlers(call) {
    call.addEventListener(CallEvents.Connected, function () {
        bridgeStarted = true;
        Logger.write('callback client answered, bridge start');
        VoxEngine.sendMediaBetween(operatorCall, call);
        VoxEngine.sendMediaBetween(call, operatorCall);
    });

    call.addEventListener(CallEvents.Failed, function (event) {
        var status = mapClientFailure(event);
        finishWithError(status, extractReason(event));
    });

    call.addEventListener(CallEvents.Disconnected, function () {
        if (!bridgeStarted) {
            finishWithError('client_no_answer', 'client_disconnected_before_bridge');
            return;
        }
        finishGracefully('connected', 'client_disconnected_after_bridge');
    });
}

function mapClientFailure(event) {
    var code = String((event && event.code) || '');
    if (code === '486') {
        return 'client_busy';
    }
    return 'client_no_answer';
}

function extractReason(event) {
    if (!event) {
        return 'unknown';
    }
    if (event.reason) {
        return String(event.reason);
    }
    if (event.code) {
        return String(event.code);
    }
    return 'unknown';
}

function hangupSafely(call) {
    try {
        if (call) {
            call.hangup();
        }
    } catch (error) {
        Logger.write('callback hangup error: ' + error.message);
    }
}

function finishWithError(status, reason) {
    if (finished) {
        return;
    }
    finished = true;
    Logger.write('callback result=' + status + ' reason=' + reason);
    hangupSafely(clientCall);
    hangupSafely(operatorCall);
    VoxEngine.terminate();
}

function finishGracefully(status, reason) {
    if (finished) {
        return;
    }
    finished = true;
    Logger.write('callback result=' + status + ' reason=' + reason);
    VoxEngine.terminate();
}
