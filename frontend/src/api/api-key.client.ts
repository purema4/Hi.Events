import {api} from "./client.ts";
import {ApiKey, CreateApiKeyRequest, GenericDataResponse, IdParam} from "../types.ts";

export const apiKeyClient = {
    all: async (accountId: IdParam) => {
        const response = await api.get<GenericDataResponse<ApiKey[]>>(`accounts/${accountId}/api-keys`);
        return response.data;
    },
    create: async (accountId: IdParam, payload: CreateApiKeyRequest) => {
        const response = await api.post<GenericDataResponse<ApiKey>>(`accounts/${accountId}/api-keys`, payload);
        return response.data;
    },
    delete: async (accountId: IdParam, apiKeyId: IdParam) => {
        return await api.delete(`accounts/${accountId}/api-keys/${apiKeyId}`);
    },
}
