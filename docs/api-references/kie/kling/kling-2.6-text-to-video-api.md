# Text To Video API Documentation

> Generate content using the Text To Video model

## Overview

This document describes how to use the Text To Video model for content generation. The process consists of two steps:
1. Create a generation task
2. Query task status and results

## Authentication

All API requests require a Bearer Token in the request header:

```
Authorization: Bearer YOUR_API_KEY
```

Get API Key:
1. Visit [API Key Management Page](https://kie.ai/api-key) to get your API Key
2. Add to request header: `Authorization: Bearer YOUR_API_KEY`

---

## 1. Create Generation Task

### API Information
- **URL**: `POST https://api.kie.ai/api/v1/jobs/createTask`
- **Content-Type**: `application/json`

### Request Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| model | string | Yes | Model name, format: `kling-2.6/text-to-video` |
| input | object | Yes | Input parameters object |
| callBackUrl | string | No | Callback URL for task completion notifications. If provided, the system will send POST requests to this URL when the task completes (success or fail). If not provided, no callback notifications will be sent. Example: `"https://your-domain.com/api/callback"` |

### Model Parameter

The `model` parameter specifies which AI model to use for content generation.

| Property | Value | Description |
|----------|-------|-------------|
| **Format** | `kling-2.6/text-to-video` | The exact model identifier for this API |
| **Type** | string | Must be passed as a string value |
| **Required** | Yes | This parameter is mandatory for all requests |

> **Note**: The model parameter must match exactly as shown above. Different models have different capabilities and parameter requirements.

### Callback URL Parameter

The `callBackUrl` parameter allows you to receive automatic notifications when your task completes.

| Property | Value | Description |
|----------|-------|-------------|
| **Purpose** | Task completion notification | Receive real-time updates when your task finishes |
| **Method** | POST request | The system sends POST requests to your callback URL |
| **Timing** | When task completes | Notifications sent for both success and failure states |
| **Content** | Query Task API response | Callback content structure is identical to the Query Task API response |
| **Parameters** | Complete request data | The `param` field contains the complete Create Task request parameters, not just the input section |
| **Optional** | Yes | If not provided, no callback notifications will be sent |

**Important Notes:**
- The callback content structure is identical to the Query Task API response
- The `param` field contains the complete Create Task request parameters, not just the input section  
- If `callBackUrl` is not provided, no callback notifications will be sent

### input Object Parameters

#### prompt
- **Type**: `string`
- **Required**: Yes
- **Description**: The text prompt used to generate the video
- **Max Length**: 2500 characters
- **Default Value**: `"Visual: In a fashion live-streaming room, clothes hang on a rack, and a full-length mirror reflects the host's figure. Dialog: [African-American female host] turns to show off the sweatshirt fit. [African-American female host, cheerful voice] says: \"360-degree flawless cut, slimming and flattering.\" Immediately, [African-American female host] moves closer to the camera. [African-American female host, lively voice] says: \"Double-sided brushed fleece, 30 dollars off with purchase now.\""`

#### sound
- **Type**: `boolean`
- **Required**: Yes
- **Description**: This parameter is used to specify whether the generated video contains sound
- **Default Value**: `false`

#### aspect_ratio
- **Type**: `string`
- **Required**: Yes
- **Description**: This parameter defines the aspect ratio of the video.
- **Options**:
  - `1:1`: 1:1
  - `16:9`: 16:9
  - `9:16`: 9:16
- **Default Value**: `"1:1"`

#### duration
- **Type**: `string`
- **Required**: Yes
- **Description**: Duration of the video in seconds
- **Options**:
  - `5`: 5
  - `10`: 10
- **Default Value**: `"5"`

### Request Example

```json
{
  "model": "kling-2.6/text-to-video",
  "input": {
    "prompt": "Visual: In a fashion live-streaming room, clothes hang on a rack, and a full-length mirror reflects the host's figure. Dialog: [African-American female host] turns to show off the sweatshirt fit. [African-American female host, cheerful voice] says: \"360-degree flawless cut, slimming and flattering.\" Immediately, [African-American female host] moves closer to the camera. [African-American female host, lively voice] says: \"Double-sided brushed fleece, 30 dollars off with purchase now.\"",
    "sound": false,
    "aspect_ratio": "1:1",
    "duration": "5"
  }
}
```
### Response Example

```json
{
  "code": 200,
  "msg": "success",
  "data": {
    "taskId": "281e5b0*********************f39b9"
  }
}
```

### Response Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| code | integer | Response status code, 200 indicates success |
| msg | string | Response message |
| data.taskId | string | Task ID for querying task status |

---

## 2. Query Task Status

### API Information
- **URL**: `GET https://api.kie.ai/api/v1/jobs/recordInfo`
- **Parameter**: `taskId` (passed via URL parameter)

### Request Example
```
GET https://api.kie.ai/api/v1/jobs/recordInfo?taskId=281e5b0*********************f39b9
```

### Response Example

```json
{
  "code": 200,
  "msg": "success",
  "data": {
    "taskId": "281e5b0*********************f39b9",
    "model": "kling-2.6/text-to-video",
    "state": "waiting",
    "param": "{\"model\":\"kling-2.6/text-to-video\",\"input\":{\"prompt\":\"Visual: In a fashion live-streaming room, clothes hang on a rack, and a full-length mirror reflects the host's figure. Dialog: [African-American female host] turns to show off the sweatshirt fit. [African-American female host, cheerful voice] says: \\"360-degree flawless cut, slimming and flattering.\\" Immediately, [African-American female host] moves closer to the camera. [African-American female host, lively voice] says: \\"Double-sided brushed fleece, 30 dollars off with purchase now.\\"\",\"sound\":false,\"aspect_ratio\":\"1:1\",\"duration\":\"5\"}}",
    "resultJson": "{\"resultUrls\":[\"https://static.aiquickdraw.com/tools/example/1764828873062_0fok52Ym.mp4\"]}",
    "failCode": null,
    "failMsg": null,
    "costTime": null,
    "completeTime": null,
    "createTime": 1757584164490
  }
}
```

### Response Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| code | integer | Response status code, 200 indicates success |
| msg | string | Response message |
| data.taskId | string | Task ID |
| data.model | string | Model name used |
| data.state | string | Task status: `waiting`(waiting),  `success`(success), `fail`(fail) |
| data.param | string | Task parameters (JSON string) |
| data.resultJson | string | Task result (JSON string, available when task is success). Structure depends on outputMediaType: `{resultUrls: []}` for image/media/video, `{resultObject: {}}` for text |
| data.failCode | string | Failure code (available when task fails) |
| data.failMsg | string | Failure message (available when task fails) |
| data.costTime | integer | Task duration in milliseconds (available when task is success) |
| data.completeTime | integer | Completion timestamp (available when task is success) |
| data.createTime | integer | Creation timestamp |

---

## Usage Flow

1. **Create Task**: Call `POST https://api.kie.ai/api/v1/jobs/createTask` to create a generation task
2. **Get Task ID**: Extract `taskId` from the response
3. **Wait for Results**: 
   - If you provided a `callBackUrl`, wait for the callback notification
   - If no `callBackUrl`, poll status by calling `GET https://api.kie.ai/api/v1/jobs/recordInfo`
4. **Get Results**: When `state` is `success`, extract generation results from `resultJson`

## Error Codes

| Status Code | Description |
|-------------|-------------|
| 200 | Request successful |
| 400 | Invalid request parameters |
| 401 | Authentication failed, please check API Key |
| 402 | Insufficient account balance |
| 404 | Resource not found |
| 422 | Parameter validation failed |
| 429 | Request rate limit exceeded |
| 500 | Internal server error |

