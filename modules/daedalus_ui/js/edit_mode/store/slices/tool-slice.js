import { createSlice } from '@reduxjs/toolkit';
import store from '../store';

const toolSlice = createSlice({
  name: 'tool',
  initialState: {
    currentTool: null,
  },
  reducers: {
    setTool: (state, action) => {
      state.currentTool = action.payload.currentTool;
    },
  },
});

export const { setTool } = toolSlice.actions;
export default toolSlice.reducer;

window.setCurrentTool = (currentTool) => {
  store.dispatch(setTool({ currentTool }));
};

window.currentTool = () => {
  return store.getState().tool.currentTool;
};
