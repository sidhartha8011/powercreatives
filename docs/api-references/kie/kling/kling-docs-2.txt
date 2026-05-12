> ## Documentation Index
> Fetch the complete documentation index at: https://docs.kie.ai/llms.txt
> Use this file to discover all available pages before exploring further.

# Grok Imagine - Image to Video

> Transform images into dynamic videos powered by Grok's advanced AI model

## Query Task Status

After submitting a task, use the unified query endpoint to check progress and retrieve results:

<Card title="Get Task Details" icon="magnifying-glass" href="/market/common/get-task-detail">
  Learn how to query task status and retrieve generation results
</Card>

<Tip>
  For production use, we recommend using the `callBackUrl` parameter to receive automatic notifications when generation completes, rather than polling the status endpoint.
</Tip>

## Related Resources

<CardGroup cols={2}>
  <Card title="Market Overview" icon="store" href="/market/quickstart">
    Explore all available models
  </Card>

  <Card title="Common API" icon="gear" href="/common-api/get-account-credits">
    Check credits and account usage
  </Card>
</CardGroup>


## OpenAPI

````yaml market/grok-imagine/image-to-video.json post /api/v1/jobs/createTask
openapi: 3.0.0
info:
  title: Grok Imagine API
  description: kie.ai Grok Imagine API Documentation - Image to Video Generation
  version: 1.0.0
  contact:
    name: Technical Support
    email: support@kie.ai
servers:
  - url: https://api.kie.ai
    description: API Server
security:
  - BearerAuth: []
paths:
  /api/v1/jobs/createTask:
    post:
      summary: Generate Grok Imagine Video from Image
      description: >-
        Create a new Grok Imagine image-to-video generation task using the
        grok-imagine/image-to-video model.


        ### Important Notes

        - Generated videos are processed asynchronously

        - Use the returned taskId to track generation progress

        - Callback URL is recommended for production use to receive automatic
        notifications when generation completes

        - Generated video URLs are valid for 24 hours - download and store
        videos immediately

        - Supports input from external image URLs or previously generated Grok
        images

        - High-quality video generation with motion from static images


        ### Generation Time

        Video generation typically takes:

        - **Standard quality**: 15-20 seconds

        - **High complexity prompts**: 20-30 seconds


        ### Rate Limits

        - Maximum 25 concurrent tasks per account

        - Maximum 250 task creations per hour

        - Single video per request


        ### Input Options

        You can provide input images in two ways:


        **1. External Image URLs** (`image_urls`):

        - Supports JPEG, PNG, WEBP formats

        - Maximum file size: 10MB

        - Only one image URL supported

        - Spicy mode not available with external images


        **2. Previously Generated Images** (`task_id` + `index`):

        - Use task ID from previous Grok image generation

        - Select specific image by index (0-5)

        - Supports all modes including Spicy

        - Do not use with `image_urls`


        ### Best Practices

        **Prompt Writing:**

        - Describe the motion, camera movement, and action you want to see

        - Be specific about timing and transitions

        - Consider the composition of your input image

        - Include details about the environment and context


        **Input Selection:**

        - Use high-quality, well-composed images as input

        - Ensure the image subject is clearly visible

        - For complex scenes, use previously generated Grok images with task_id

        - Test different modes to achieve desired motion style


        ### Mode Selection

        - **fun**: More creative and playful interpretation

        - **normal**: Balanced approach with good motion quality

        - **spicy**: More dynamic and intense motion effects (not available for
        external images)


        ### Common Issues

        **Video quality not as expected:** Try different prompts, adjust mode,
        or use different input images

        **Generation taking too long:** Typical generation is 15-30 seconds;
        check task status or verify callback URL accessibility

        **Motion doesn't match prompt:** Be more specific about movement
        directions, camera angles, and timing
      operationId: grok-imagine-image-to-video
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              properties:
                model:
                  type: string
                  enum:
                    - grok-imagine/image-to-video
                  default: grok-imagine/image-to-video
                  description: |-
                    The model name to use for generation. Required field.

                    - Must be `grok-imagine/image-to-video` for this endpoint
                  example: grok-imagine/image-to-video
                callBackUrl:
                  type: string
                  format: uri
                  description: >-
                    The URL to receive video generation task completion updates.
                    Optional but recommended for production use.


                    - System will POST task status and results to this URL when
                    video generation completes

                    - Callback includes generated video URLs and task
                    information

                    - Your callback endpoint should accept POST requests with
                    JSON payload containing video results

                    - Alternatively, use the Get Task Details endpoint to poll
                    task status

                    - To ensure callback security, see [Webhook Verification
                    Guide](/common-api/webhook-verification) for signature
                    verification implementation
                  example: https://your-domain.com/api/callback
                input:
                  type: object
                  description: Input parameters for the video generation task
                  properties:
                    image_urls:
                      type: array
                      items:
                        type: string
                        format: uri
                      description: >-
                        Provide one external image URL as reference for video
                        generation. Only one image is supported. Do not use with
                        task_id.


                        - Supports JPEG, PNG, WEBP formats

                        - Maximum file size: 10MB per image

                        - Spicy mode not available when using external images

                        - Array should contain exactly one URL
                      maxItems: 1
                      example:
                        - >-
                          https://file.aiquickdraw.com/custom-page/akr/section-images/1762247692373tw5di116.png
                    task_id:
                      type: string
                      description: >-
                        Task ID from a previously generated Grok image. Use with
                        index to select a specific image. Do not use with
                        image_urls.


                        - Use task ID from grok-imagine/text-to-image
                        generations

                        - Supports all modes including Spicy

                        - Maximum length: 100 characters
                      maxLength: 100
                      example: task_grok_12345678
                    index:
                      type: integer
                      description: >-
                        When using task_id, specify which image to use (Grok
                        generates 6 images per task). Only works with task_id.


                        - 0-based index (0-5)

                        - Ignored if image_urls is provided

                        - Default: 0
                      minimum: 0
                      maximum: 5
                      default: 0
                      example: 0
                    prompt:
                      type: string
                      description: >-
                        Text prompt describing the desired video motion.
                        Optional field.


                        - Should be detailed and specific about the desired
                        visual motion

                        - Describe movement, action sequences, camera work, and
                        timing

                        - Include details about subjects, environments, and
                        motion dynamics

                        - Maximum length: 5000 characters

                        - Supports English language prompts
                      example: >-
                        POV hand comes into frame handing the girl a cup of take
                        away coffee, the girl steps out of the screen looking
                        tired, then takes it and she says happily: "thanks! Back
                        to work" she exits the frame and walks right to a
                        different part of the office.
                    mode:
                      type: string
                      description: >-
                        Specifies the generation mode affecting the style and
                        intensity of motion. Note: Spicy mode is not available
                        for external image inputs.


                        - **fun**: More creative and playful interpretation

                        - **normal**: Balanced approach with good motion quality

                        - **spicy**: More dynamic and intense motion effects
                        (not available for external images)


                        Default: normal
                      enum:
                        - fun
                        - normal
                        - spicy
                      default: normal
                      example: normal
                    duration:
                      description: The duration of the generated video in seconds
                      type: string
                      enum:
                        - '6'
                        - '10'
                      default: '6'
                      example: '6'
                    resolution:
                      description: |-
                        The resolution of the generated video.
                        - **480p**: Standard definition quality (default)
                        - **720p**: High definition quality
                      type: string
                      enum:
                        - 480p
                        - 720p
                      default: 480p
                      example: 480p
              required:
                - model
                - input
              example:
                model: grok-imagine/image-to-video
                callBackUrl: https://your-domain.com/api/callback
                input:
                  image_urls:
                    - >-
                      https://file.aiquickdraw.com/custom-page/akr/section-images/1762247692373tw5di116.png
                  prompt: >-
                    POV hand comes into frame handing the girl a cup of take
                    away coffee, the girl steps out of the screen looking tired,
                    then takes it and she says happily: "thanks! Back to work"
                    she exits the frame and walks right to a different part of
                    the office.
                  mode: normal
                  duration: '6'
                  resolution: 480p
      responses:
        '200':
          description: Request successful
          content:
            application/json:
              schema:
                allOf:
                  - $ref: '#/components/schemas/ApiResponse'
                  - type: object
                    properties:
                      data:
                        type: object
                        properties:
                          taskId:
                            type: string
                            description: >-
                              Task ID, can be used with Get Task Details
                              endpoint to query task status
                            example: task_grok_video_12345678
              example:
                code: 200
                msg: success
                data:
                  taskId: 281e5b0*********************f39b9
        '500':
          $ref: '#/components/responses/Error'
      callbacks:
        onVideoGenerated:
          '{$request.body#/callBackUrl}':
            post:
              summary: Video Generation Callback
              description: >-
                When the video generation task is completed, the system will
                send the result to your provided callback URL via POST request
              requestBody:
                required: true
                content:
                  application/json:
                    schema:
                      type: object
                      properties:
                        code:
                          type: integer
                          description: >-
                            Status code


                            - **200**: Success - Video generation completed
                            successfully

                            - **501**: Failed - Video generation task failed
                          enum:
                            - 200
                            - 501
                        msg:
                          type: string
                          description: Status message
                          example: Playground task completed successfully.
                        data:
                          type: object
                          properties:
                            taskId:
                              type: string
                              description: Task ID
                              example: 281e5b0*********************f39b9
                    example:
                      code: 200
                      msg: Playground task completed successfully.
                      data:
                        taskId: 281e5b0*********************f39b9
              responses:
                '200':
                  description: Callback received successfully
components:
  schemas:
    ApiResponse:
      type: object
      properties:
        code:
          type: integer
          enum:
            - 200
            - 401
            - 402
            - 404
            - 422
            - 429
            - 455
            - 500
            - 501
            - 505
          description: >-
            Response status code


            - **200**: Success - Request has been processed successfully

            - **401**: Unauthorized - Authentication credentials are missing or
            invalid

            - **402**: Insufficient Credits - Account does not have enough
            credits to perform the operation

            - **404**: Not Found - The requested resource or endpoint does not
            exist

            - **422**: Validation Error - The request parameters failed
            validation checks

            - **429**: Rate Limited - Request limit has been exceeded for this
            resource

            - **455**: Service Unavailable - System is currently undergoing
            maintenance

            - **500**: Server Error - An unexpected error occurred while
            processing the request

            - **501**: Generation Failed - Video generation task failed

            - **505**: Feature Disabled - The requested feature is currently
            disabled
        msg:
          type: string
          description: Response message, error description when failed
          example: success
  responses:
    Error:
      description: Server Error
  securitySchemes:
    BearerAuth:
      type: http
      scheme: bearer
      bearerFormat: API Key
      description: >-
        All APIs require authentication via Bearer Token.


        Get API Key:

        1. Visit [API Key Management Page](https://kie.ai/api-key) to get your
        API Key


        Usage:

        Add to request header:

        Authorization: Bearer YOUR_API_KEY


        Note:

        - Keep your API Key secure and do not share it with others

        - If you suspect your API Key has been compromised, reset it immediately
        in the management page

````